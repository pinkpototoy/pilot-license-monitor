<?php

namespace App\Models;

use App\Enums\ComplianceState;
use App\Enums\StudentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use HasFactory;

    /** Fields Staff may edit through the record screens (status/compliance are controlled). */
    protected $fillable = [
        'student_number', 'first_name', 'middle_name', 'last_name', 'date_of_birth',
        'email', 'contact_number', 'program_id', 'cohort',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'status' => StudentStatus::class,
            'compliance_state' => ComplianceState::class,
            'compliance_evaluated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class);
    }

    public function currentCredentials(): HasMany
    {
        return $this->credentials()->whereNull('archived_at');
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.($this->middle_name ? mb_substr($this->middle_name, 0, 1).'. ' : '').$this->last_name);
    }

    public function scopeMonitored(Builder $q): Builder
    {
        return $q->whereIn('status', [StudentStatus::Active->value, StudentStatus::OnLeave->value]);
    }

    /** FR-013: search by name, student number, email or license number. */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $q->where(function (Builder $w) use ($like) {
            $w->where('student_number', 'ilike', $like)
                ->orWhere('email', 'ilike', $like)
                ->orWhereRaw("(first_name || ' ' || last_name) ilike ?", [$like])
                ->orWhereHas('credentials', fn (Builder $c) => $c->where('license_number', 'ilike', $like));
        });
    }
}
