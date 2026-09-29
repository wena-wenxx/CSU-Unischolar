<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Scholarship extends Model
{
    protected $fillable = [
        'name', 'description', 'provider', 'application_start', 'application_end', 'amount', 'status',
    ];

    public function requirements() { return $this->hasMany(ScholarshipRequirement::class); }
    public function applications() { return $this->hasMany(Application::class); }
}
