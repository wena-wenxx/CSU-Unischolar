<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ValidationResult extends Model
{
    protected $fillable = [
        'document_id',
        'is_complete',
        'has_name_mismatch',
        'has_missing_information',
        'has_wrong_document',
        'confidence_score',
        'extracted_text',
        'extracted_data',
        'flags',
    ];

    protected $casts = [
        'is_complete' => 'boolean',
        'has_name_mismatch' => 'boolean',
        'has_missing_information' => 'boolean',
        'has_wrong_document' => 'boolean',
        'extracted_data' => 'array',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
