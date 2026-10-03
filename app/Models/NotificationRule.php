<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FR-050 — offset_days: negative = before expiry, 0 = expiry day, positive = after. */
class NotificationRule extends Model
{
    protected $fillable = ['credential_type_id', 'offset_days', 'recipients', 'channels', 'template_code', 'active'];

    protected function casts(): array
    {
        return ['channels' => 'array', 'active' => 'boolean', 'offset_days' => 'integer'];
    }

    public function credentialType(): BelongsTo
    {
        return $this->belongsTo(CredentialType::class);
    }

    public function describe(): string
    {
        return match (true) {
            $this->offset_days < 0 => abs($this->offset_days).' days before expiry',
            $this->offset_days === 0 => 'On the expiry date',
            default => $this->offset_days.' days after expiry',
        };
    }
}
