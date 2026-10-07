<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    protected $fillable = [
        'student_id', 'application_id', 'type', 'to_email', 'subject', 'trigger', 'status', 'error', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function student() { return $this->belongsTo(Student::class); }
    public function application() { return $this->belongsTo(Application::class); }
}
