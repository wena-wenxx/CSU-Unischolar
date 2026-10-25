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
        return $query->where('student_id', $studentId)->counting();
    }

    /**
     * Lines that count as "holds an outside scholarship" now: linked to a student, not ended by staff,
     * and not from a past school year (a 2024-2025 list does not block a student in 2026-2027).
     * A line with no school year counts until staff mark it as ended.
     */
    public function scopeCounting($query)
    {
        $current = self::currentSchoolYear();

        return $query->where('match_status', 'matched')
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('school_year')->orWhere('school_year', '>=', $current));
    }

    /** "2026-2027": from Settings, or from today's date (August starts a new school year). */
    public static function currentSchoolYear(): string
    {
        $set = Setting::get('current_school_year');
        if ($set && preg_match('/^\d{4}-\d{4}$/', $set)) {
            return $set;
        }
        $now = now('Asia/Manila');
        $start = $now->month >= 8 ? $now->year : $now->year - 1;

        return $start.'-'.($start + 1);
    }

    protected $appends = ['past_school_year'];

    public function getPastSchoolYearAttribute(): bool
    {
        return $this->isPastSchoolYear();
    }

    /** True when the line is from a school year that has already ended. */
    public function isPastSchoolYear(): bool
    {
        return $this->school_year !== null && $this->school_year < self::currentSchoolYear();
    }

    // "LGU-Butuan City Scholarship (external, City Government of Butuan)"
    public function label(): string
    {
        return $this->program_name.' (external'.($this->agency ? ', '.$this->agency : '').')';
    }
}
