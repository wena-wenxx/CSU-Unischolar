<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Student extends Model
{
    protected $fillable = [
        'user_id',
        'student_id',
        'first_name',
        'middle_name',
        'last_name',
        'course',
        'year_level',
        'college',
        'contact_number',
    ];

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
