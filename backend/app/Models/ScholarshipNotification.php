<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScholarshipNotification extends Model
{
    protected $fillable = [
        'student_id',
        'scholarship_id',
        'sender_id',
        'ai_recommendation_id',
        'type',
        'message',
        'sent_at',
        'read_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function student() { return $this->belongsTo(Student::class); }
    public function scholarship() { return $this->belongsTo(Scholarship::class); }
    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
    public function recommendation() { return $this->belongsTo(AiRecommendation::class, 'ai_recommendation_id'); }
}
