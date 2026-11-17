<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgencyListUpload extends Model
{
    protected $fillable = [
        'scholarship_id', 'agency_name', 'file_name', 'uploaded_by', 'total_rows',
        'approved_count', 'created_count', 'already_count', 'unmatched_count', 'error_count', 'details',
        'school_year', 'semester', 'batch', 'reference_number', 'list_date',
        'expected_count', 'expected_total', 'notes',
    ];

    protected $casts = [
        'details' => 'array',
        'list_date' => 'date:Y-m-d',
        'expected_count' => 'integer',
        'expected_total' => 'decimal:2',
    ];

    public function scholarship()
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
