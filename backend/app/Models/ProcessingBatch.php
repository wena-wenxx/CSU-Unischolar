<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Processing batch: one processing cycle of one program
|--------------------------------------------------------------------------
| e.g. "TES - 1st Semester AY 2026-2027". Applications, approved lists,
| scholar records and payroll entries of the cycle point to it. Completing a
| batch only changes its status: no record is deleted, and the Data Bank keeps
| every student and their history.
*/
class ProcessingBatch extends Model
{
    /** Application statuses that still need OAS action. */
    public const PENDING_STATUSES = ['submitted', 'under_review', 'needs_action'];

    protected $fillable = [
        'scholarship_id', 'name', 'school_year', 'semester', 'status', 'notes',
        'created_by', 'completed_at', 'completed_by',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function scholarship() { return $this->belongsTo(Scholarship::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function completer() { return $this->belongsTo(User::class, 'completed_by'); }
    public function applications() { return $this->hasMany(Application::class); }
    public function uploads() { return $this->hasMany(AgencyListUpload::class); }
    public function scholarRecords() { return $this->hasMany(ScholarRecord::class); }
    public function payrollRecords() { return $this->hasMany(PayrollRecord::class); }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    /** Default name: "TES - 1st Semester AY 2026-2027". */
    public static function defaultName(Scholarship $scholarship, ?string $schoolYear, ?string $semester): string
    {
        $label = $scholarship->short_name ?: $scholarship->name;
        $period = trim(($semester ? $semester.' ' : '').($schoolYear ? 'AY '.$schoolYear : ''));

        return mb_substr($period !== '' ? "{$label} - {$period}" : $label, 0, 140);
    }

    /*
    | The open batch new work of this program and period goes into. A batch for
    | the same semester is preferred, then one with no semester. When there is
    | none, a new open batch is created, so no record is ever left without one.
    */
    public static function forWork(int $scholarshipId, ?string $schoolYear, ?string $semester = null, ?int $userId = null): ?self
    {
        $scholarship = Scholarship::find($scholarshipId);
        if (!$scholarship) {
            return null;
        }

        $open = self::open()->where('scholarship_id', $scholarshipId)
            ->where('school_year', $schoolYear)
            ->orderByDesc('id')
            ->get();

        $match = $semester
            ? ($open->firstWhere('semester', $semester) ?? $open->firstWhere('semester', null))
            : ($open->firstWhere('semester', null) ?? $open->first());

        if ($match) {
            return $match;
        }

        return self::create([
            'scholarship_id' => $scholarshipId,
            'name' => self::uniqueName($scholarshipId, self::defaultName($scholarship, $schoolYear, $semester)),
            'school_year' => $schoolYear,
            'semester' => $semester,
            'status' => 'open',
            'notes' => 'Created automatically for new work in this period.',
            'created_by' => $userId,
        ]);
    }

    /** "TES - AY 2026-2027", "TES - AY 2026-2027 (2)", ... */
    public static function uniqueName(int $scholarshipId, string $name, ?int $exceptId = null): string
    {
        $candidate = $name;
        $n = 2;
        while (self::where('scholarship_id', $scholarshipId)->where('name', $candidate)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists()) {
            $candidate = mb_substr($name, 0, 140)." ({$n})";
            $n++;
        }

        return $candidate;
    }

    /** Counts shown on the Processing Batches page. */
    public function counts(): array
    {
        $applications = $this->applications()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $uploads = $this->uploads()->get(['id', 'details']);
        $unmatched = $uploads->sum(fn (AgencyListUpload $u) => $u->openRowCount());

        return [
            'applications' => (int) $applications->sum(),
            'pending' => (int) collect(self::PENDING_STATUSES)->sum(fn ($s) => $applications[$s] ?? 0),
            'forwarded' => (int) ($applications['complete'] ?? 0),
            'approved' => (int) ($applications['approved'] ?? 0),
            'rejected' => (int) ($applications['rejected'] ?? 0),
            'drafts' => (int) ($applications['draft'] ?? 0),
            'lists' => $uploads->count(),
            'unmatched' => (int) $unmatched,
            'grantees' => $this->scholarRecords()->count(),
            'payroll_entries' => $this->payrollRecords()->count(),
            'payroll_processed' => $this->payrollRecords()->where('status', 'processed')->count(),
        ];
    }

    /** What still needs action before the batch should be completed (empty = ready). */
    public function openItems(?array $counts = null): array
    {
        $counts ??= $this->counts();
        $items = [];
        if ($counts['pending']) {
            $items[] = "{$counts['pending']} application(s) still need OAS action (submitted, under review or needs action)";
        }
        if ($counts['unmatched']) {
            $items[] = "{$counts['unmatched']} row(s) of an approved list are still unmatched or need checking";
        }
        $notReady = $this->payrollRecords()->where('status', '!=', 'processed')->count();
        if ($notReady) {
            $items[] = "{$notReady} payroll entr".($notReady === 1 ? 'y is' : 'ies are').' not yet processed';
        }

        return $items;
    }
}
