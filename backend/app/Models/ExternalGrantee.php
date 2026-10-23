<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/*
| One line of a saved external grantee list. When match_status = matched and
| status = active, the student "holds an external scholarship": it shows on
| their profile and counts for the one-active-scholarship rule.
*/
class ExternalGrantee extends Model
{
    protected $fillable = [
        'upload_id', 'student_id', 'row_number', 'extracted_name', 'extracted_student_id', 'program_name', 'agency',
        'category', 'school_year', 'match_status', 'match_method', 'match_score', 'status', 'ended_at', 'note', 'confirmed_by',
    ];

    protected $casts = [
        'ended_at' => 'datetime',
        'match_score' => 'integer',
    ];

    public function upload() { return $this->belongsTo(ExternalGranteeUpload::class, 'upload_id'); }
    public function student() { return $this->belongsTo(Student::class); }
    public function confirmer() { return $this->belongsTo(User::class, 'confirmed_by'); }

    /** Holdings that count right now for this student. */
    public function scopeActiveFor($query, int $studentId)
    {
        return $query->where('student_id', $studentId)
            ->where('match_status', 'matched')
            ->where('status', 'active');
    }

    // "LGU-Butuan City Scholarship (external, City Government of Butuan)"
    public function label(): string
    {
        return $this->program_name.' (external'.($this->agency ? ', '.$this->agency : '').')';
    }
}
