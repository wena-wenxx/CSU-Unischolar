<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    protected $fillable = [
        'student_id',
        'scholarship_id',
        'school_year',
        'status',
        'remarks',
        'submitted_at',
        'forwarded_at',
        'enrollment_verified',
        'enrollment_verified_at',
        // from the agency's approved list (e.g. CHED TES billing file)
        'agency_control_number',
        'agency_award_number',
        'agency_amount',
        'agency_batch',
        'agency_list_upload_id',
    ];

    protected $casts = [
        'agency_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'forwarded_at' => 'datetime',
        'enrollment_verified' => 'boolean',
        'enrollment_verified_at' => 'datetime',
    ];

    /** A new application belongs to the school year in which it is started (one per program per year). */
    protected static function booted(): void
    {
        static::creating(function (Application $application) {
            if (empty($application->school_year)) {
                $application->school_year = $application->created_at
                    ? self::schoolYearOf($application->created_at)
                    : ExternalGrantee::currentSchoolYear();
            }
        });
    }

    /** "2026-2027" for any date (a school year starts in August). */
    public static function schoolYearOf($date): string
    {
        $date = \Illuminate\Support\Carbon::parse($date);
        $start = $date->month >= 8 ? $date->year : $date->year - 1;

        return $start.'-'.($start + 1);
    }

    /** Rows inserted directly (e.g. the bulk demo seeder) get the school year of their start date. */
    public static function fillMissingSchoolYears(): int
    {
        $count = 0;
        foreach (self::whereNull('school_year')->get(['id', 'created_at']) as $application) {
            $application->timestamps = false;
            $application->update(['school_year' => self::schoolYearOf($application->created_at ?? now())]);
            $count++;
        }

        return $count;
    }

    // Lets the student pages tell this year's application from an old one.
    protected $appends = ['this_school_year'];

    public function getThisSchoolYearAttribute(): bool
    {
        return $this->school_year === ExternalGrantee::currentSchoolYear();
    }

    public function scopeThisSchoolYear($query)
    {
        return $query->where('school_year', ExternalGrantee::currentSchoolYear());
    }

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

    // Every step this application went through, oldest first.
    public function statusLogs()
    {
        return $this->hasMany(ApplicationStatusLog::class)->orderBy('created_at')->orderBy('id');
    }

    // The most recent step (used for "last activity" on the staff list).
    public function latestLog()
    {
        return $this->hasOne(ApplicationStatusLog::class)->latestOfMany();
    }

    /*
    | What OAS staff may change an application to, from each status.
    | This keeps the steps in order: OAS checks the documents, forwards the
    | complete application to the agency, then records the agency's decision.
    |   draft         -> (nothing: the student has not submitted yet)
    |   submitted     -> under review, needs action, complete (forward)
    |   under_review  -> needs action, complete (forward)
    |   needs_action  -> under review (the student normally resubmits)
    |   complete      -> approved / rejected by the agency, or back to under review
    |   approved      -> complete ("undo", only while not yet tagged as grantee)
    |   rejected      -> complete ("undo")
    */
    public const STAFF_NEXT = [
        'draft' => [],
        'submitted' => ['under_review', 'needs_action', 'complete'],
        'under_review' => ['needs_action', 'complete'],
        'needs_action' => ['under_review'],
        'complete' => ['approved', 'rejected', 'under_review'],
        'approved' => ['complete'],
        'rejected' => ['complete'],
    ];

    /** Statuses staff may move this application to right now. */
    public function staffNextStatuses(): array
    {
        $next = self::STAFF_NEXT[$this->status] ?? [];

        // An approved student who is already tagged as a grantee cannot be
        // "un-approved" here; change the scholar record instead.
        if ($this->status === 'approved' && $this->isTaggedGrantee()) {
            $next = [];
        }

        // Only a complete application (no missing required document) can be
        // forwarded to the agency.
        if (in_array('complete', $next, true) && $this->status !== 'approved' && $this->status !== 'rejected'
            && $this->missingRequirementNames()) {
            $next = array_values(array_diff($next, ['complete']));
        }

        return $next;
    }

    /** Why a status change is not allowed, or null when it is. */
    public function staffChangeError(string $to): ?string
    {
        // Saving remarks without changing the status is always fine once submitted.
        if ($to === $this->status && $this->status !== 'draft') {
            return null;
        }

        if ($this->status === 'draft') {
            return 'This application is still a draft. The student has not submitted it yet.';
        }

        if ($to === 'complete' && !in_array($this->status, ['approved', 'rejected'], true)
            && ($missing = $this->missingRequirementNames())) {
            return 'Cannot forward to the agency: missing required documents ('.implode(', ', $missing).').';
        }

        if ($this->status === 'approved' && $to === 'complete' && $this->isTaggedGrantee()) {
            return 'This student is already tagged as a grantee. Change the scholar record instead.';
        }

        if (!in_array($to, self::STAFF_NEXT[$this->status] ?? [], true)) {
            $from = str_replace('_', ' ', $this->status);
            $wanted = str_replace('_', ' ', $to);

            if (in_array($to, ['approved', 'rejected'], true)) {
                return "The agency's decision can only be recorded after the application is complete and forwarded (now: {$from}).";
            }

            return "An application that is \"{$from}\" cannot be changed to \"{$wanted}\".";
        }

        return null;
    }

    public function isTaggedGrantee(): bool
    {
        return ScholarRecord::where('student_id', $this->student_id)
            ->where('scholarship_id', $this->scholarship_id)
            ->exists();
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

    /*
    | forwarded_at for applications that were forwarded before this column
    | existed: the date of their "complete" step, else the submission date.
    */
    public static function backfillForwardedAt(): int
    {
        $count = 0;

        $apps = self::query()
            ->whereNull('forwarded_at')
            ->whereIn('status', ['complete', 'approved', 'rejected'])
            ->get(['id', 'submitted_at']);

        foreach ($apps as $app) {
            $at = ApplicationStatusLog::where('application_id', $app->id)
                ->where('to_status', 'complete')
                ->latest('created_at')
                ->value('created_at') ?? $app->submitted_at;

            if ($at) {
                self::whereKey($app->id)->update(['forwarded_at' => $at]);
                $count++;
            }
        }

        return $count;
    }
}
