<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = ['application_id', 'scholarship_requirement_id', 'file_path', 'uploaded_at'];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function validationResult()
    {
        return $this->hasOne(ValidationResult::class);
    }
}