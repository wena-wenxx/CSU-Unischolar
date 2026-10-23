<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/*
| One recommendation = one student x one scholarship, calculated by
| App\Support\EligibilityEngine. It only SUGGESTS: staff decide (staff_status).
*/
class AiRecommendation extends Model
{
    public const VERDICTS = ['eligible', 'not_eligible', 'no_data'];

    // What OAS staff did with the recommendation.
    public const STAFF_STATUSES = ['new', 'invited', 'added', 'dismissed', 'wrong'];

    protected $fillable = [
        'student_id', 'scholarship_id', 'score', 'met_count', 'total_count', 'verdict', 'checks', 'calculation',
        'applied_status', 'data_verified', 'staff_status', 'staff_note', 'decided_by', 'decided_at', 'invited_at', 'computed_at',
    ];

    protected $casts = [
        'checks' => 'array',
        'data_verified' => 'boolean',
        'decided_at' => 'datetime',
        'invited_at' => 'datetime',
        'computed_at' => 'datetime',
        'score' => 'integer',
        'met_count' => 'integer',
        'total_count' => 'integer',
    ];

    public function student() { return $this->belongsTo(Student::class); }
    public function scholarship() { return $this->belongsTo(Scholarship::class); }
    public function decider() { return $this->belongsTo(User::class, 'decided_by'); }
    public function logs() { return $this->hasMany(RecommendationLog::class)->latest('created_at')->latest('id'); }
}
