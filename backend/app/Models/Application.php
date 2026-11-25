<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    protected $fillable = [
        'student_id',
        'scholarship_id',
        'school_year',
        'semester',
        'processing_batch_id',
        'status',
        'remarks',
        'submitted_at',
        'forwarded_at',
        'agency_submission_id',
        'enrollment_verified',
        'enrollment_verified_at',
        'enrollment_method',
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

    protected static function booted(): void
    {
        static::creating(function (Application $application) {
            if (empty($application->school_year)) {
                $application->school_year = $application->created_at
                    ? self::schoolYearOf($application->created_at)
                    : ExternalGrantee::currentSchoolYear();
            }
            if (empty($application->semester) && $application->school_year === ExternalGrantee::currentSchoolYear()) {
                $semester = Setting::get('current_semester');
                $application->semester = in_array($semester, Setting::SEMESTERS, true) ? $semester : null;
            }
            if (empty($application->processing_batch_id) && $application->scholarship_id) {
                $application->processing_batch_id = ProcessingBatch::forWork(
                    (int) $application->scholarship_id, $application->school_year, $application->semester, auth()->id()
                )?->id;
            }
        });
    }

    public static function fillMissingBatches(): int
    {
        $count = 0;
        foreach (self::whereNull('processing_batch_id')->whereNotNull('school_year')
            ->get(['id', 'scholarship_id', 'school_year', 'semester']) as $application) {
            $batch = ProcessingBatch::forWork((int) $application->scholarship_id, $application->school_year, $application->semester);
            if ($batch) {
                $application->timestamps = false;
                $application->update(['processing_batch_id' => $batch->id]);
                $count++;
            }
        }

        return $count;
    }

    public static function schoolYearOf($date): string
    {
        $date = \Illuminate\Support\Carbon::parse($date);
        $start = $date->month >= 8 ? $date->year : $date->year - 1;

        return $start.'-'.($start + 1);
    }

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

    protected $appends = ['this_school_year'];

    public function getThisSchoolYearAttribute(): bool
    {
        return $this->school_year === ExternalGrantee::currentSchoolYear();
    }

    public function scopeThisSchoolYear($query)
    {
        return $query->where('school_year', ExternalGrantee::currentSchoolYear());
    }

    public function scopeInOpenWork($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('processing_batch_id')
                ->orWhereHas('processingBatch', fn ($b) => $b->where('status', '!=', 'completed'));
        });
    }

    public function inOpenWork(): bool
    {
        return !$this->processing_batch_id || $this->processingBatch?->status !== 'completed';
    }

    public function agencySubmission()
    {
        return $this->belongsTo(AgencySubmission::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function scholarship()
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function processingBatch()
    {
        return $this->belongsTo(ProcessingBatch::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function statusLogs()
    {
        return $this->hasMany(ApplicationStatusLog::class)->orderBy('created_at')->orderBy('id');
    }

    public function latestLog()
    {
        return $this->hasOne(ApplicationStatusLog::class)->latestOfMany();
    }

    public const STAFF_NEXT = [
        'draft' => [],
        'submitted' => ['under_review', 'needs_action', 'complete'],
        'under_review' => ['needs_action', 'complete'],
        'needs_action' => ['under_review'],
        'complete' => ['approved', 'rejected', 'under_review'],
        'approved' => ['complete'],
        'rejected' => ['complete'],
    ];

    public function staffNextStatuses(): array
    {
        $next = self::STAFF_NEXT[$this->status] ?? [];

        if ($this->status === 'approved' && $this->isTaggedGrantee()) {
            $next = [];
        }

        if (in_array('complete', $next, true) && $this->status !== 'approved' && $this->status !== 'rejected'
            && $this->missingRequirementNames()) {
            $next = array_values(array_diff($next, ['complete']));
        }

        return $next;
    }

    public function staffChangeError(string $to): ?string
    {
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
            ->where('school_year', $this->school_year)
            ->exists();
    }

    public function missingRequirementNames(): array
    {
        $this->loadMissing('scholarship.requirements', 'documents');

        $covered = $this->documents->pluck('scholarship_requirement_id')->filter()->unique()->all();

        return $this->scholarship->requirements
            ->where('is_required', true)
            ->reject(fn ($req) => in_array($req->id, $covered))
            ->pluck('name')->values()->all();
    }

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
