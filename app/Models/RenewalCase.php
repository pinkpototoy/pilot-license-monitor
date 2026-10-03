<?php

namespace App\Models;

use App\Enums\RenewalCaseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** SRS 13.2 / 14 — one renewal attempt for one credential. */
class RenewalCase extends Model
{
    protected $fillable = ['credential_id', 'opened_by', 'status'];

    protected function casts(): array
    {
        return [
            'status' => RenewalCaseStatus::class,
            'locked_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'draft_warning_sent_at' => 'datetime',
        ];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_reviewer_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'case_id')->orderBy('requirement_id')->orderByDesc('version_no');
    }

    /** The latest version per checklist item (superseded versions are history). */
    public function currentDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'case_id')->where('verification_status', '<>', 'superseded');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RenewalCaseEvent::class, 'case_id')->orderBy('id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', RenewalCaseStatus::openValues());
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
