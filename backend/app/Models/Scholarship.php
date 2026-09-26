<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Scholarship extends Model
{
    protected $fillable = ['name', 'source_agency', 'description', 'active'];

    public function requirements()
    {
        return $this->hasMany(ScholarshipRequirement::class);
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }
}