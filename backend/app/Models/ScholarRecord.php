<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScholarRecord extends Model
{
    protected $fillable = ['student_id', 'scholarship_id', 'status', 'date_tagged'];

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