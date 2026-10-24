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
        'sex',
        'course',
        'year_level',
        'college',
        'contact_number',
        // Scholarship matching data (typed in by the student, checked by OAS)
        'interests',   // optional "My field and goals" (AI field fit)
        'gwa',
        'family_income',
        'eligibility_updated_at',
        'eligibility_verified_at',
        'eligibility_verified_by',
    ];

    protected $casts = [
        'gwa' => 'decimal:2',
        'family_income' => 'decimal:2',
        'eligibility_updated_at' => 'datetime',
        'eligibility_verified_at' => 'datetime',
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

    // Scholarships from outside parties (LGU, foundations, ...) read from uploaded lists.
    public function externalGrantees()
    {
        return $this->hasMany(ExternalGrantee::class);
    }

    public function eligibilityVerifier()
    {
        return $this->belongsTo(User::class, 'eligibility_verified_by');
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /*
    | One active scholarship per student: the scholarship this student holds
    | right now, other than $exceptScholarshipId. Checks both the grantee
    | records in ScholarGuide and the external grantee lists.
    | Returns e.g. "CHED Tertiary Education Subsidy (TES)" or
    | "LGU-Butuan City Scholarship (external, City Government of Butuan)", or null.
    */
    public function otherActiveScholarship(?int $exceptScholarshipId = null): ?string
    {
        $record = ScholarRecord::with('scholarship:id,name')
            ->where('student_id', $this->id)
            ->where('status', 'active')
            ->when($exceptScholarshipId, fn ($q, $id) => $q->where('scholarship_id', '!=', $id))
            ->first();

        if ($record) {
            return $record->scholarship?->name ?? 'another scholarship';
        }

        $external = ExternalGrantee::activeFor($this->id)->first();

        return $external ? $external->label() : null;
    }
}
