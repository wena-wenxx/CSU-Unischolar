<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $fillable = ['user_id', 'student_no', 'name', 'program', 'year_level', 'enrollment_status'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    public function scholarRecords()
    {
        return $this->hasMany(ScholarRecord::class);
    }
}