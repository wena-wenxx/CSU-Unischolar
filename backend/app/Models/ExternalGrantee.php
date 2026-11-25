<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function scopeActiveFor($query, int $studentId)
    {
        return $query->where('student_id', $studentId)->counting();
    }

    public function scopeCounting($query)
    {
        $current = self::currentSchoolYear();

        return $query->where('match_status', 'matched')
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('school_year')->orWhere('school_year', '>=', $current));
    }

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

    public function isPastSchoolYear(): bool
    {
        return $this->school_year !== null && $this->school_year < self::currentSchoolYear();
    }

    public function label(): string
    {
        return $this->program_name.' (external'.($this->agency ? ', '.$this->agency : '').')';
    }
}
