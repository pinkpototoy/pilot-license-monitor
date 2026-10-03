<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** SRS 15 — one message on one channel to one recipient (table: notifications). */
class OutboundNotification extends Model
{
    protected $table = 'notifications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(NotificationRule::class, 'rule_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(NotificationAttempt::class, 'notification_id')->orderBy('attempt_no');
    }

    public function recipientAddress(): ?string
    {
        return $this->recipient_email ?? $this->user?->email;
    }
}
