<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    public const TOPICS = [
        'application' => 'My application',
        'documents' => 'Documents',
        'payroll' => 'Stipend / payroll / ATM',
        'account' => 'My account or profile',
        'other' => 'Something else',
    ];

    protected $fillable = [
        'student_id', 'topic', 'subject', 'message', 'status', 'reply', 'replied_by', 'replied_at', 'reply_read_at',
    ];

    protected $casts = [
        'replied_at' => 'datetime',
        'reply_read_at' => 'datetime',
    ];

    public function student() { return $this->belongsTo(Student::class); }
    public function replier() { return $this->belongsTo(User::class, 'replied_by'); }
}
