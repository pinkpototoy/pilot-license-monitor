<?php

namespace App\Jobs;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\Scanner;
use App\Domain\Notifications\NotificationService;
use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/** SRS 14 step 3 — scan before staff can open the file; quarantine on detection. */
class ScanDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly int $documentId) {}

    public function handle(Scanner $scanner, AuditLogger $audit, NotificationService $notifications): void
    {
        $doc = Document::with('student', 'documentType', 'renewalCase')->find($this->documentId);
        if (! $doc || $doc->scan_status !== 'pending') {
            return;
        }
        $disk = Storage::disk('documents');
        $result = $scanner->scan($disk->path($doc->storage_key));

        if ($result['result'] === 'error') {
            // Leave it pending; the queue retries. After the last try it is marked error.
            if ($this->attempts() >= $this->tries) {
                $doc->update(['scan_status' => 'error']);
                $audit->record('document.scan_error', 'document', $doc->id, $doc->student_id, null, $result['signature']);
            } else {
                $this->release($this->backoff[$this->attempts() - 1] ?? 3600);
            }

            return;
        }

        if ($result['result'] === 'infected') {
            $quarantineKey = 'quarantine/'.basename($doc->storage_key);
            $disk->move($doc->storage_key, $quarantineKey);
            $doc->update(['scan_status' => 'infected', 'storage_key' => $quarantineKey]);
            $audit->record('document.quarantined', 'document', $doc->id, $doc->student_id, null, 'Malware detected: '.$result['signature']);
            $notifications->event('upload_infected', $doc->student, [
                'action_line' => "The file \"{$doc->original_filename}\" you uploaded for {$doc->documentType->name} was blocked by the virus scan and has been removed. Upload a clean copy.",
            ], ['student', 'staff'], "infected:{$doc->id}", $doc->renewalCase);

            return;
        }

        $doc->update(['scan_status' => 'clean']);
    }
}
