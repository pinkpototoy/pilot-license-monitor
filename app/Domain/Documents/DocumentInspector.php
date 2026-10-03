<?php

namespace App\Domain\Documents;

use App\Domain\DomainRuleViolation;
use App\Models\DocumentType;
use Illuminate\Http\UploadedFile;

/**
 * FR-032, FR-033, BR-023, EC-18 — server-side checks on every upload.
 * The type is decided by the file's content (magic bytes), never by its name.
 */
final class DocumentInspector
{
    /** @return array{mime: string, extension: string, size: int, sha256: string, display_name: string} */
    public function inspect(UploadedFile $file, DocumentType $type): array
    {
        if (! $file->isValid()) {
            throw DomainRuleViolation::on('file', 'The upload did not complete. Try again.');
        }
        $path = $file->getRealPath();
        $size = (int) $file->getSize();
        $maxMb = $type->max_size_mb ?: 10;

        if ($size === 0) {
            throw DomainRuleViolation::on('file', 'This file is empty.');
        }
        if ($size > $maxMb * 1048576) {
            throw DomainRuleViolation::on('file', 'BR-023: This file is '.number_format($size / 1048576, 1)." MB. The limit for {$type->name} is {$maxMb} MB. Scan at a lower resolution or save as a smaller PDF.");
        }

        $allowed = array_intersect_key(config('splms.documents.allowed'), array_flip($type->accepted_mime_types ?? []));
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        $ext = strtolower($file->getClientOriginalExtension());

        if (! isset($allowed[$detected])) {
            throw DomainRuleViolation::on('file', 'FR-033: Only '.$this->describe($allowed).' files are accepted. This file is '.$this->friendly($detected).'.');
        }
        if (! in_array($ext, $allowed[$detected], true)) {
            throw DomainRuleViolation::on('file', "FR-033: The file name ends in .{$ext} but its content is ".$this->friendly($detected).'. Upload the original file without renaming it.');
        }

        $detected === 'application/pdf' ? $this->checkPdf($path) : $this->checkImage($path);

        return [
            'mime' => $detected,
            'extension' => $ext === 'jpeg' ? 'jpg' : $ext,
            'size' => $size,
            'sha256' => hash_file('sha256', $path),
            'display_name' => $this->safeName($file->getClientOriginalName()),
        ];
    }

    private function checkPdf(string $path): void
    {
        $fh = fopen($path, 'rb');
        $head = fread($fh, 1024);
        fseek($fh, max(0, filesize($path) - 2048));
        $tail = fread($fh, 2048);
        fclose($fh);

        if (! str_starts_with(ltrim($head), '%PDF-') || ! str_contains($tail, '%%EOF')) {
            throw DomainRuleViolation::on('file', 'EC-18: This PDF looks damaged or incomplete. Save or export it again and re-upload.');
        }
        // Encrypted (password-protected) PDFs can't be reviewed.
        $sample = file_get_contents($path, false, null, 0, min(filesize($path), 5 * 1048576));
        if (preg_match('#/Encrypt\s#', $sample)) {
            throw DomainRuleViolation::on('file', 'EC-18: This PDF is password-protected. Remove the password, then upload it again.');
        }
    }

    private function checkImage(string $path): void
    {
        $info = @getimagesize($path);
        if (! $info || $info[0] < 200 || $info[1] < 200) {
            throw DomainRuleViolation::on('file', 'This image can\'t be read or is too small to review (minimum 200 × 200 pixels). Take a clearer photo.');
        }
        if ($info[0] > 12000 || $info[1] > 12000) {
            throw DomainRuleViolation::on('file', 'This image is too large to display. Reduce it to under 12,000 pixels on each side.');
        }
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[^\pL\pN ._\-()]+/u', '_', $name) ?? 'document';

        return mb_substr(trim($name, ' ._') ?: 'document', 0, 120);
    }

    private function describe(array $allowed): string
    {
        $exts = array_map('strtoupper', array_unique(array_map(fn ($e) => $e[0], $allowed)));

        return implode(', ', array_slice($exts, 0, -1)).(count($exts) > 1 ? ' or ' : '').end($exts);
    }

    private function friendly(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'a PDF',
            'image/jpeg' => 'a JPG image',
            'image/png' => 'a PNG image',
            'application/x-dosexec', 'application/x-msdownload', 'application/x-executable' => 'a program (not a document)',
            'application/zip' => 'a ZIP archive',
            default => "of type {$mime}",
        };
    }
}
