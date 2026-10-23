<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/*
| A file of outside-scholarship grantees uploaded by OAS staff
| (status: review -> saved, or discarded). See ExternalGranteeController.
*/
class ExternalGranteeUpload extends Model
{
    public const CATEGORIES = [
        'lgu' => 'Local government (LGU)',
        'ched' => 'CHED',
        'government' => 'Other government agency',
        'private' => 'Private foundation / company',
        'other' => 'Other',
    ];

    protected $fillable = [
        'program_name', 'agency', 'category', 'school_year', 'file_name', 'file_path', 'file_type', 'status',
        'read_method', 'warnings', 'rows', 'total_rows', 'matched_count', 'check_count', 'unmatched_count',
        'error_count', 'uploaded_by', 'saved_by', 'saved_at',
    ];

    protected $casts = [
        'warnings' => 'array',
        'rows' => 'array',
        'saved_at' => 'datetime',
    ];

    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function saver() { return $this->belongsTo(User::class, 'saved_by'); }
    public function grantees() { return $this->hasMany(ExternalGrantee::class, 'upload_id'); }
}
