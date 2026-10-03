<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SRS 22.4 — one uploaded file version. The file itself lives on the private 'documents' disk. */
class Document extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['storage_key'];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function renewalCase(): BelongsTo
    {
        return $this->belongsTo(RenewalCase::class, 'case_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(CredentialTypeRequirement::class, 'requirement_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function rejectionReason(): BelongsTo
    {
        return $this->belongsTo(RejectionReason::class, 'rejection_reason_code', 'code');
    }

    public function isViewable(): bool
    {
        return $this->scan_status === 'clean';
    }

    /** Uploaded by someone other than the student (staff on their behalf) — shown to reviewers. */
    public function uploadedOnBehalf(): bool
    {
        return $this->student?->user_id === null || $this->uploaded_by !== $this->student->user_id;
    }

    public function humanSize(): string
    {
        return $this->size_bytes >= 1048576
            ? number_format($this->size_bytes / 1048576, 1).' MB'
            : max(1, (int) round($this->size_bytes / 1024)).' KB';
    }
}
