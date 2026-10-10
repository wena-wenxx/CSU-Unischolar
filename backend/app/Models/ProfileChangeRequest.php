<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileChangeRequest extends Model
{
    // Registrar-owned fields a student may ask OAS to correct.
    public const FIELDS = [
        'first_name' => 'First name',
        'middle_name' => 'Middle name',
        'last_name' => 'Last name',
        'sex' => 'Sex',
        'student_id' => 'Student ID',
        'course' => 'Course',
        'year_level' => 'Year level',
        'college' => 'College',
    ];

    protected $fillable = [
        'student_id', 'field', 'requested_value', 'reason', 'status', 'staff_remarks', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
