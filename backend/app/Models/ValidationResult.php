<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ValidationResult extends Model
{
    protected $fillable = ['document_id', 'extracted_text', 'completeness_flag', 'name_match_flag', 'reviewed_by_staff'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}