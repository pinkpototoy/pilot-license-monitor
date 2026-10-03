<?php

namespace App\Models;

use App\Enums\RenewalCaseStatus;
use App\Enums\ValidityStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Credential extends Model
{
    use HasFactory;

    /** current_status is deliberately NOT fillable: only the compliance engine writes it (BR-016). */
    protected $fillable = ['student_id', 'credential_type_id', 'license_number', 'created_by'];

    protected function casts(): array
    {
        return [
            'current_status' => ValidityStatus::class,
            'status_evaluated_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(CredentialType::class, 'credential_type_id');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(CredentialPeriod::class)->orderByDesc('id');
    }

    public function currentPeriod(): BelongsTo
    {
        return $this->belongsTo(CredentialPeriod::class, 'current_period_id');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(StatusOverride::class);
    }

    public function activeOverride(): HasOne
    {
        return $this->hasOne(StatusOverride::class)->whereNull('ended_at');
    }

    public function renewalCases(): HasMany
    {
        return $this->hasMany(RenewalCase::class);
    }

    public function openRenewalCase(): HasOne
    {
        return $this->hasOne(RenewalCase::class)->whereIn('status', RenewalCaseStatus::openValues());
    }
}
