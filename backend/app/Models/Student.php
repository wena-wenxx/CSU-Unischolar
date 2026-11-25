<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $fillable = [
        'user_id',
        'is_demo',
        'student_id',
        'first_name',
        'middle_name',
        'last_name',
        'sex',
        'course',
        'year_level',
        'college',
        'contact_number',
        'interests',
        'gwa',
        'family_income',
        'eligibility_updated_at',
        'eligibility_verified_at',
        'eligibility_verified_by',
    ];

    protected $casts = [
        'is_demo' => 'boolean',
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

    public function externalGrantees()
    {
        return $this->hasMany(ExternalGrantee::class);
    }

    public function eligibilityVerifier()
    {
        return $this->belongsTo(User::class, 'eligibility_verified_by');
    }

    public function fullName(bool $middleInitial = false): string
    {
        $middle = trim((string) $this->middle_name);
        $initial = $middleInitial && $middle !== '' && $middle !== 'Demo' ? ' '.mb_strtoupper(mb_substr($middle, 0, 1)).'.' : '';

        return trim("{$this->first_name}{$initial} {$this->last_name}");
    }

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
