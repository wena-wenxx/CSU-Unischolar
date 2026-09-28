<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScholarRecord extends Model
{
    protected $fillable = [
        'student_id',
        'scholarship_id',
        'status',
        'currently_enrolled',
        'has_atm',
        'grantee_tagged_at',
        'remarks',
    ];

    protected $casts = [
        'currently_enrolled' => 'boolean',
        'has_atm' => 'boolean',
        'grantee_tagged_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function scholarship()
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function payrollRecords()
    {
        return $this->hasMany(PayrollRecord::class);
    }
}
