<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One list of applications OAS actually sent to an agency, on one date. */
class AgencySubmission extends Model
{
    protected $fillable = [
        'scholarship_id', 'processing_batch_id', 'sent_on', 'agency_name', 'sent_by_way', 'note',
        'application_count', 'created_by',
    ];

    protected $casts = [
        'sent_on' => 'date:Y-m-d',
        'application_count' => 'integer',
    ];

    public function scholarship()
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function processingBatch()
    {
        return $this->belongsTo(ProcessingBatch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }
}
