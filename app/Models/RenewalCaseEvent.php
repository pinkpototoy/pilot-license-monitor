<?php

namespace App\Models;

use App\Enums\RenewalCaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RenewalCaseEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['case_id', 'from_status', 'to_status', 'actor_id', 'remarks'];

    protected function casts(): array
    {
        return ['from_status' => RenewalCaseStatus::class, 'to_status' => RenewalCaseStatus::class];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
