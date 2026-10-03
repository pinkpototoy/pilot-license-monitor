<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    protected $fillable = ['code', 'name', 'description', 'accepted_mime_types', 'max_size_mb'];

    protected function casts(): array
    {
        return ['accepted_mime_types' => 'array'];
    }
}
