<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScholarshipRequirement extends Model
{
    protected $fillable = ['scholarship_id', 'requirement_name', 'description'];

    public function scholarship()
    {
        return $this->belongsTo(Scholarship::class);
    }
}