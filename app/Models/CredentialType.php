<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CredentialType extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'issuing_authority', 'expires', 'default_validity_months',
        'max_validity_months', 'expiring_soon_days', 'number_pattern', 'active',
    ];

    protected function casts(): array
    {
        return ['expires' => 'boolean', 'active' => 'boolean'];
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(CredentialTypeRequirement::class)->orderBy('sort_order');
    }

    /** FR-022: validate a license number against the configured pattern, if any. */
    public function acceptsNumber(?string $number): bool
    {
        if ($this->number_pattern === null || $number === null) {
            return true;
        }

        return (bool) preg_match('/^(?:'.$this->number_pattern.')$/i', $number);
    }
}
