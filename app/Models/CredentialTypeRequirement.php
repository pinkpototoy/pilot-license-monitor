<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CredentialTypeRequirement extends Model
{
    protected $fillable = ['credential_type_id', 'document_type_id', 'mandatory', 'sort_order'];

    protected function casts(): array
    {
        return ['mandatory' => 'boolean'];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }
}
