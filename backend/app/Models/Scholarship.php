<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Scholarship extends Model
{
    // The OAS is in the Philippines: "today" for deadlines is Manila time.
    public const TIMEZONE = 'Asia/Manila';

    // Grouped like the OAS list: CHED-funded, other government, private, university.
    // ("lgu" is kept for older records.)
    public const CATEGORIES = ['ched', 'government', 'private', 'csu', 'lgu'];

    public const MODES = ['oas', 'agency_direct'];

    protected $fillable = [
        'name', 'short_name', 'description', 'provider', 'category', 'application_mode',
        'application_start', 'application_end', 'amount', 'status',
    ];

    // Sent with every scholarship so the frontend can show "Open", "Closed", ...
    protected $appends = ['availability', 'is_open'];

    public function requirements() { return $this->hasMany(ScholarshipRequirement::class); }
    public function applications() { return $this->hasMany(Application::class); }

    public static function today(): string
    {
        return now(self::TIMEZONE)->toDateString();
    }

    private function day($value): ?string
    {
        return $value ? substr((string) $value, 0, 10) : null;
    }

    /*
    | open          active, and today is inside the application period
    | upcoming      active, but the period has not started yet
    | deadline_passed  active, but application_end is before today
    | closed / inactive  set by staff
    */
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

    // Students apply directly at the agency, not in ScholarGuide.
    public function isAgencyDirect(): bool
    {
        return $this->application_mode === 'agency_direct';
    }

    // Students may browse programs that are open or opening soon.
    public function scopeVisibleToStudents($query)
    {
        $today = self::today();

        return $query->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('application_end')->orWhere('application_end', '>=', $today));
    }

    // Plain-language reason a student cannot apply, or null when open.
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
}
