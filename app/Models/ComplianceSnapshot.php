<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceSnapshot extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot_date' => 'date', 'expiry_date' => 'date'];
    }
}
