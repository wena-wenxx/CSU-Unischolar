<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgencyListUpload extends Model
{
    protected $fillable = [
        'scholarship_id', 'agency_name', 'file_name', 'uploaded_by', 'total_rows',
        'approved_count', 'created_count', 'already_count', 'unmatched_count', 'error_count', 'details',
    ];

    protected $casts = [
        'details' => 'array',
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
