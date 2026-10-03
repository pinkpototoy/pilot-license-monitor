<?php

namespace App\Models;

use App\Enums\PeriodSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CredentialPeriod extends Model
{
    protected $fillable = [
        'credential_id', 'license_number', 'issue_date', 'expiry_date', 'source',
        'renewal_case_id', 'import_batch_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'source' => PeriodSource::class,
            'superseded_at' => 'datetime',
        ];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }
}
