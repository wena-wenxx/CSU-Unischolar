<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnrollmentListEntry extends Model
{
    public $timestamps = false;

    protected $fillable = ['enrollment_list_id', 'student_number', 'last_name', 'first_name', 'course'];

    public function list() { return $this->belongsTo(EnrollmentList::class, 'enrollment_list_id'); }
}
