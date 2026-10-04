<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    protected $fillable = [
        'student_id',
        'scholarship_id',
        'status',
        'remarks',
        'submitted_at',
        'enrollment_verified',
        'enrollment_verified_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'enrollment_verified' => 'boolean',
        'enrollment_verified_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function scholarship()
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    /** Names of REQUIRED requirements that have no uploaded document yet. */
    public function missingRequirementNames(): array
    {
        $this->loadMissing('scholarship.requirements', 'documents');

        $covered = $this->documents->pluck('scholarship_requirement_id')->filter()->unique()->all();

        return $this->scholarship->requirements
            ->where('is_required', true)
            ->reject(fn ($req) => in_array($req->id, $covered))
            ->pluck('name')->values()->all();
    }
}
