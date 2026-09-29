<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'application_id', 'scholarship_requirement_id', 'original_filename', 'file_path', 'document_type', 'status',
    ];

    public function application() { return $this->belongsTo(Application::class); }
    public function requirement() { return $this->belongsTo(ScholarshipRequirement::class, 'scholarship_requirement_id'); }
    public function validationResult() { return $this->hasOne(ValidationResult::class); }
}
