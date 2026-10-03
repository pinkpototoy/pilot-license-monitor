<?php

namespace App\Models;

use App\Enums\ValidityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatusOverride extends Model
{
    protected $fillable = ['credential_id', 'override_status', 'reason', 'valid_until', 'created_by'];

    protected function casts(): array
    {
        return [
            'override_status' => ValidityStatus::class,
            'valid_until' => 'date',
            'ended_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
