<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScholarshipRequirement extends Model
{
    protected $fillable = [
        'scholarship_id',
        'name',
        'description',
        'is_required',
    ];

    protected $casts = [
        'is_required' => 'boolean',
    ];

    public function scholarship()
    {
        return $this->belongsTo(Scholarship::class);
    }
}
