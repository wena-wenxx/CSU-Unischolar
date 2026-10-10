<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\PayrollRecord;
use App\Models\ScholarRecord;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller
{
    private const TERMS = ['1st Semester', '2nd Semester', 'Summer'];

    private function staffOnly(Request $request): void
    {
        if ($request->user()->role !== 'staff') {
            abort(response()->json(['message' => 'Unauthorized.'], 403));
        }
    }

    /*
    | The term we are in now. The admin sets it in System Settings (school
    | year + semester). If it was never set, it is worked out from today's
    | date (Philippine time), CSU calendar roughly:
    |   August-December = 1st Semester, January-May = 2nd Semester,
    |   June-July = Summer. Returns [AY start year, term index].
    */
    private static function currentTerm(): array
    {
        $year = Setting::get('current_school_year');
        $semester = Setting::get('current_semester');

        if ($year && $semester && preg_match('/^(\d{4})-\d{4}$/', $year, $m)
            && ($index = array_search($semester, self::TERMS, true)) !== false) {
            return [(int) $m[1], $index];
        }

        $now = Carbon::now('Asia/Manila');
        $month = $now->month;

        if ($month >= 8) return [$now->year, 0];
        if ($month <= 5) return [$now->year - 1, 1];

        return [$now->year - 1, 2];
    }

    private static function termLabel(int $year, int $index): string
    {
        return self::TERMS[$index].' AY '.$year.'-'.($year + 1);
    }

    /** "1st Semester AY 2026-2027": the current term, used as the default period. */
    public static function currentPeriod(): string
    {
        [$year, $index] = self::currentTerm();

        return self::termLabel($year, $index);
    }

    private function samePeriod(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    /*
    | GET /payroll/periods
    | Choices for the Period dropdown: the last three terms, the current term
    | and the next one, plus any other period already used in payroll.
    */
    public function periods(Request $request)
    {
        $this->staffOnly($request);

        [$year, $index] = self::currentTerm();
        $current = self::termLabel($year, $index);

        // Step back three terms, then list five terms in order.
        for ($i = 0; $i < 3; $i++) {
            $index--;
            if ($index < 0) {
                $index = 2;
                $year--;
            }
        }

        $list = [];
        for ($i = 0; $i < 5; $i++) {
            $list[] = self::termLabel($year, $index);
            $index++;
            if ($index > 2) {
                $index = 0;
                $year++;
            }
        }

        $used = PayrollRecord::query()->distinct()->pluck('period')->all();
        foreach ($used as $period) {
            if (!collect($list)->contains(fn ($p) => $this->samePeriod($p, $period))) {
                $list[] = $period;
            }
        }

        return response()->json(['current' => $current, 'periods' => array_values($list)]);
    }

    /*
    | POST /payroll/prepare
    | Body: period (required), scholarship_id (optional: one program, or all),
    |       amount (optional: only with one program, replaces its amount),
    |       confirm (false = preview only, true = create the draft entries)
    |
    | Every active grantee is checked. Included: currently enrolled, not yet
    | in payroll for this period, and the program has an amount. ATM problems
    | do not stop preparation; they are shown as warnings.
    */
    public function prepare(Request $request)
    {
        $this->staffOnly($request);

        $data = $request->validate([
            'period' => 'required|string|max:100',
            'scholarship_id' => 'nullable|exists:scholarships,id',
            'amount' => 'nullable|numeric|min:1',
            'confirm' => 'sometimes|boolean',
        ]);

        if (isset($data['amount']) && empty($data['scholarship_id'])) {
            return response()->json([
                'message' => 'A different amount can only be set when one scholarship program is chosen.'
            ], 422);
        }

        $period = trim(preg_replace('/\s+/', ' ', $data['period']));

        $records = ScholarRecord::with(['student', 'scholarship:id,name,amount,application_mode', 'payrollRecords:id,scholar_record_id,period'])
            ->where('status', 'active')
            ->when($data['scholarship_id'] ?? null, fn ($q, $id) => $q->where('scholarship_id', $id))
            ->get()
            ->sortBy(fn ($r) => [$r->scholarship?->name, $r->student?->last_name, $r->student?->first_name])
            ->values();

        $rows = $records->map(function (ScholarRecord $record) use ($data, $period) {
            $amount = $data['amount'] ?? $record->scholarship?->amount;
            $reason = null;

            if ($record->scholarship?->application_mode === 'agency_direct') {
                $reason = 'Paid directly by the agency';
            } elseif (!$record->currently_enrolled) {
                $reason = 'Not currently enrolled';
            } elseif ($record->payrollRecords->contains(fn ($p) => $this->samePeriod($p->period, $period))) {
                $reason = 'Already in payroll for this period';
            } elseif (!$amount || (float) $amount <= 0) {
                $reason = 'The program has no amount set';
            }

            $warning = null;
            if (!$record->has_atm) {
                $warning = 'No ATM yet'.($record->atm_note ? ' ('.$record->atm_note.')' : '');
            } elseif ($record->atm_funds !== 'yes') {
                $warning = $record->atm_funds === 'no' ? 'ATM has no funds yet' : 'ATM funds pending';
            }

            return [
                'scholar_record_id' => $record->id,
                'student_id' => $record->student?->student_id,
                'student' => trim(($record->student?->last_name ?? '').', '.($record->student?->first_name ?? ''), ', '),
                'scholarship' => $record->scholarship?->name,
                'scholarship_id' => $record->scholarship_id,
                'amount' => $amount !== null ? (float) $amount : null,
                'atm' => $record->atm_label,
                'include' => $reason === null,
                'reason' => $reason,
                'warning' => $warning,
            ];
        });

        $included = $rows->where('include', true)->values();

        $summary = [
            'period' => $period,
            'included' => $included->count(),
            'skipped' => $rows->count() - $included->count(),
            'total_amount' => round($included->sum('amount'), 2),
            'with_warnings' => $included->whereNotNull('warning')->count(),
        ];

        if (empty($data['confirm'])) {
            return response()->json(['summary' => $summary, 'rows' => $rows->values()]);
        }

        if ($included->isEmpty()) {
            return response()->json(['message' => 'Nobody to add: every grantee was skipped.', 'summary' => $summary], 422);
        }

        $labels = $records->keyBy('id');

        DB::transaction(function () use ($included, $period, $labels, $request) {
            foreach ($included as $row) {
                PayrollRecord::create([
                    'scholar_record_id' => $row['scholar_record_id'],
                    'amount' => $row['amount'],
                    'period' => $period,
                    'bank_atm_status' => mb_substr($labels[$row['scholar_record_id']]->atm_label, 0, 50),
                    'status' => 'draft',
                    'prepared_by' => $request->user()->id,
                ]);
            }
        });

        $program = isset($data['scholarship_id']) ? $records->first()?->scholarship?->name ?? 'one program' : 'all programs';
        ActivityLog::record($request->user(), 'payroll.prepared',
            "Prepared payroll for {$period} ({$program}): {$summary['included']} entries, total ".number_format($summary['total_amount'], 2).'.');

        return response()->json([
            'message' => "Payroll prepared: {$summary['included']} draft entries for {$period}.",
            'summary' => $summary,
        ], 201);
    }

    /*
    | POST /payroll/bulk-status   Body: ids [..], status draft|ready|processed
    | Allowed steps: draft -> ready -> processed, and ready -> draft.
    */
    public function bulkStatus(Request $request)
    {
        $this->staffOnly($request);

        $data = $request->validate([
            'ids' => 'required|array|min:1|max:1000',
            'ids.*' => 'integer',
            'status' => 'required|in:draft,ready,processed',
        ]);

        $from = ['ready' => ['draft'], 'processed' => ['ready'], 'draft' => ['ready']][$data['status']];

        $changed = PayrollRecord::whereIn('id', $data['ids'])
            ->whereIn('status', $from)
            ->update(['status' => $data['status'], 'updated_at' => now()]);

        $skipped = count(array_unique($data['ids'])) - $changed;

        if ($changed) {
            ActivityLog::record($request->user(), 'payroll.'.$data['status'], "Marked {$changed} payroll entr".($changed === 1 ? 'y' : 'ies')." as {$data['status']}.");
        }

        return response()->json([
            'message' => "{$changed} entr".($changed === 1 ? 'y' : 'ies')." marked {$data['status']}."
                .($skipped ? " {$skipped} skipped (not in the right step)." : ''),
            'changed' => $changed,
            'skipped' => $skipped,
        ]);
    }

    /*
    | GET /payroll/history
    | One row per period and program: number of scholars, total amount and
    | how many entries are draft / ready / processed. Newest first.
    */
    public function history(Request $request)
    {
        $this->staffOnly($request);

        $rows = PayrollRecord::with('scholarRecord.scholarship:id,name')
            ->get()
            ->groupBy(fn ($p) => mb_strtolower(trim($p->period)).'|'.$p->scholarRecord?->scholarship_id)
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'period' => $first->period,
                    'scholarship_id' => $first->scholarRecord?->scholarship_id,
                    'scholarship' => $first->scholarRecord?->scholarship?->name,
                    'scholars' => $group->count(),
                    'total_amount' => round($group->sum('amount'), 2),
                    'draft' => $group->where('status', 'draft')->count(),
                    'ready' => $group->where('status', 'ready')->count(),
                    'processed' => $group->where('status', 'processed')->count(),
                    'last_change' => $group->max('updated_at'),
                ];
            })
            ->sortByDesc('last_change')
            ->values();

        return response()->json($rows);
    }
    /*
    |--------------------------------------------------------------------------
    | STAFF - LIST PAYROLL
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        if ($request->user()->role !== 'staff' && !$request->user()->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        // Only the columns the payroll list and CSV use (keeps the page fast).
        $records = PayrollRecord::with([
            'scholarRecord:id,student_id,scholarship_id,status,currently_enrolled,has_atm,atm_funds,atm_note',
            'scholarRecord.student:id,student_id,first_name,middle_name,last_name,course,year_level',
            'scholarRecord.scholarship:id,name,short_name,application_mode',
            'preparer:id,name',
        ])
            ->latest()
            ->get();

        return response()->json($records);
    }


    /*
    |--------------------------------------------------------------------------
    | STAFF - CREATE PAYROLL RECORD
    |--------------------------------------------------------------------------
    */

    public function store(Request $request, $scholarRecordId = null)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        // The route is /scholar-records/{scholarRecordId}/payroll, so the
        // scholar record ID normally comes from the URL. A body value is
        // still accepted for older clients.
        if ($scholarRecordId !== null) {
            $request->merge(['scholar_record_id' => $scholarRecordId]);
        }

        $data = $request->validate([
            'scholar_record_id' =>
                'required|exists:scholar_records,id',
            'amount' =>
                'required|numeric|min:0',
            'period' =>
                'required|string|max:255',
            'bank_atm_status' =>
                'nullable|string|max:50',
            'status' =>
                'nullable|in:draft,ready,processed',
            'signature' =>
                'nullable|string|max:255',
        ]);

        $scholar = ScholarRecord::findOrFail(
            $data['scholar_record_id']
        );

        if ($scholar->status !== 'active') {
            return response()->json([
                'message' => 'Only active scholar records can be added to payroll.'
            ], 422);
        }

        if (!$scholar->currently_enrolled) {
            return response()->json([
                'message' => 'Student must be currently enrolled before payroll preparation.'
            ], 422);
        }

        $record = PayrollRecord::create([
            'scholar_record_id' =>
                $data['scholar_record_id'],
            'amount' =>
                $data['amount'],
            'period' =>
                $data['period'],
            // If staff did not type an ATM status, use the scholar record's
            // ATM status ("ATM · funded", "No ATM · For ATM application" ...).
            'bank_atm_status' =>
                $data['bank_atm_status']
                ?? mb_substr($scholar->atm_label, 0, 50),
            'prepared_by' => $request->user()->id,
            'status' =>
                $data['status'] ?? 'draft',
            'signature' =>
                $data['signature'] ?? null,
        ]);

        return response()->json([
            'message' => 'Payroll record created successfully.',
            'payroll' => $record->load([
                'scholarRecord.student',
                'scholarRecord.scholarship'
            ])
        ], 201);
    }


    /*
    |--------------------------------------------------------------------------
    | STAFF - UPDATE PAYROLL
    |--------------------------------------------------------------------------
    */

    public function update(Request $request, $id)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $data = $request->validate([
            'amount' =>
                'sometimes|numeric|min:0',
            'period' =>
                'sometimes|string|max:255',
            'bank_atm_status' =>
                'sometimes|string|max:50',
            'status' =>
                'sometimes|in:draft,ready,processed',
            'signature' =>
                'nullable|string|max:255',
        ]);

        $payroll = PayrollRecord::findOrFail($id);

        $payroll->update($data);

        return response()->json([
            'message' => 'Payroll record updated successfully.',
            'payroll' =>
                $payroll->fresh()->load([
                    'scholarRecord.student',
                    'scholarRecord.scholarship'
                ])
        ]);
    }
}
