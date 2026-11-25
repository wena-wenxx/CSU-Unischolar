<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Scholarship extends Model
{
    public const TIMEZONE = 'Asia/Manila';

    public const CATEGORIES = ['ched', 'government', 'private', 'csu', 'lgu'];

    public const MODES = ['oas', 'agency_direct'];

    protected $fillable = [
        'name', 'short_name', 'description', 'provider', 'category', 'application_mode',
        'application_start', 'application_end', 'amount', 'status',
        'max_gwa', 'max_family_income', 'year_levels', 'colleges', 'one_scholarship_only',
        'rules_source', 'rules_updated_by', 'rules_updated_at',
    ];

    protected $casts = [
        'max_gwa' => 'decimal:2',
        'max_family_income' => 'decimal:2',
        'year_levels' => 'array',
        'colleges' => 'array',
        'one_scholarship_only' => 'boolean',
        'rules_updated_at' => 'datetime',
    ];

    protected $appends = ['availability', 'is_open'];

    public function requirements() { return $this->hasMany(ScholarshipRequirement::class); }
    public function applications() { return $this->hasMany(Application::class); }
    public function recommendations() { return $this->hasMany(AiRecommendation::class); }

    public static function today(): string
    {
        return now(self::TIMEZONE)->toDateString();
    }

    private function day($value): ?string
    {
        return $value ? substr((string) $value, 0, 10) : null;
    }

    public function getAvailabilityAttribute(): string
    {
        if ($this->status !== 'active') {
            return $this->status ?: 'inactive';
        }

        $today = self::today();
        $start = $this->day($this->application_start);
        $end = $this->day($this->application_end);

        if ($start && $today < $start) return 'upcoming';
        if ($end && $today > $end) return 'deadline_passed';

        return 'open';
    }

    public function getIsOpenAttribute(): bool
    {
        return $this->availability === 'open';
    }

    public function isAgencyDirect(): bool
    {
        return $this->application_mode === 'agency_direct';
    }

    public function scopeVisibleToStudents($query)
    {
        $today = self::today();

        return $query->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('application_end')->orWhere('application_end', '>=', $today));
    }

    public function closedReason(): ?string
    {
        $start = $this->day($this->application_start);
        $end = $this->day($this->application_end);

        if ($this->isAgencyDirect()) {
            return 'Apply directly at '.($this->provider ?: 'the agency').'. This scholarship is not applied for through the OAS.';
        }

        return match ($this->availability) {
            'open' => null,
            'upcoming' => 'Applications for this scholarship open on '.date('F j, Y', strtotime($start)).'.',
            'deadline_passed' => 'Applications for this scholarship closed on '.date('F j, Y', strtotime($end)).'.',
            default => 'This scholarship is not open for applications.',
        };
    }

    public function rulesEditor() { return $this->belongsTo(User::class, 'rules_updated_by'); }
}
