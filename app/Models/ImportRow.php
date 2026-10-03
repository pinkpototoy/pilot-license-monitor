<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportRow extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['raw_data' => 'array', 'messages' => 'array', 'created_entity_ids' => 'array'];
    }
}
