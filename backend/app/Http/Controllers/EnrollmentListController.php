<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\EnrollmentList;
use App\Models\EnrollmentListEntry;
use App\Models\ScholarRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnrollmentListController extends Controller
{
    private function staffOnly(Request $request): void
    {
        if ($request->user()->role !== 'staff') {
            abort(response()->json(['message' => 'Only OAS staff can verify enrollment.'], 403));
        }
    }

    public function index(Request $request)
    {
        $this->staffOnly($request);

        return response()->json([
            'lists' => EnrollmentList::with('uploader:id,name')->latest()->latest('id')->limit(20)->get(),
            'waiting' => Application::where('status', 'approved')->where('enrollment_verified', false)->inOpenWork()->count(),
            'grantees' => ScholarRecord::where('status', 'active')->count(),
            'why_empty' => [
                'in_completed_batches' => Application::where('status', 'approved')->where('enrollment_verified', false)
                    ->whereHas('processingBatch', fn ($b) => $b->where('status', 'completed'))->count(),
                'already_verified' => Application::where('status', 'approved')->where('enrollment_verified', true)->count(),
                'forwarded_waiting_for_agency' => Application::where('status', 'complete')->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->staffOnly($request);

        $data = $request->validate([
            'period' => 'required|string|max:100',
            'file_name' => 'required|string|max:255',
            'rows' => 'required|array|min:1|max:30000',
            'rows.*.student_id' => 'nullable|string|max:50',
            'rows.*.last_name' => 'nullable|string|max:255',
            'rows.*.first_name' => 'nullable|string|max:255',
            'rows.*.course' => 'nullable|string|max:255',
        ]);

        $rows = collect($data['rows'])
            ->filter(fn ($row) => EnrollmentList::normalizeId($row['student_id'] ?? '') !== '')
            ->unique(fn ($row) => EnrollmentList::normalizeId($row['student_id']))
            ->values();

        if ($rows->isEmpty()) {
            return response()->json(['message' => 'No Student IDs were found in the chosen column.'], 422);
        }

        $list = DB::transaction(function () use ($data, $rows, $request) {
            $list = EnrollmentList::create([
                'period' => trim($data['period']),
                'file_name' => $data['file_name'],
                'uploaded_by' => $request->user()->id,
                'rows_count' => $rows->count(),
            ]);

            foreach ($rows->chunk(500) as $chunk) {
                EnrollmentListEntry::insert($chunk->map(fn ($row) => [
                    'enrollment_list_id' => $list->id,
                    'student_number' => EnrollmentList::normalizeId($row['student_id']),
                    'last_name' => isset($row['last_name']) ? trim($row['last_name']) : null,
                    'first_name' => isset($row['first_name']) ? trim($row['first_name']) : null,
                    'course' => isset($row['course']) ? trim($row['course']) : null,
                ])->all());
            }

            return $list;
        });

        $skipped = count($data['rows']) - $rows->count();

        ActivityLog::record($request->user(), 'enrollment.list_uploaded',
            "Uploaded the Registrar list {$list->file_name} ({$list->period}): {$list->rows_count} students.", $list);

        return response()->json([
            'message' => "Registrar list saved: {$list->rows_count} students".($skipped ? " ({$skipped} empty or repeated rows skipped)" : '').'.',
            'list' => $list->load('uploader:id,name'),
        ], 201);
    }

    public function rows(Request $request, int $id)
    {
        $this->staffOnly($request);
        $list = EnrollmentList::with('uploader:id,name')->findOrFail($id);
        $entries = EnrollmentListEntry::where('enrollment_list_id', $list->id)->orderBy('id')->get();

        $students = \App\Models\Student::whereNotNull('student_id')->get(['id', 'student_id', 'last_name', 'first_name'])
            ->keyBy(fn ($s) => EnrollmentList::normalizeId($s->student_id));
        $ids = $students->pluck('id');
        $approved = Application::with('scholarship:id,short_name,name')->whereIn('student_id', $ids)->where('status', 'approved')
            ->get(['id', 'student_id', 'scholarship_id', 'enrollment_verified'])->groupBy('student_id');
        $grants = ScholarRecord::with('scholarship:id,short_name,name')->whereIn('student_id', $ids)->where('status', 'active')
            ->get(['id', 'student_id', 'scholarship_id'])->groupBy('student_id');

        $label = fn ($x) => $x->scholarship?->short_name ?: $x->scholarship?->name;
        $out = $entries->values()->map(function ($e, $i) use ($students, $approved, $grants, $label) {
            $student = $students[$e->student_number] ?? null;
            $row = ['no' => $i + 1, 'student_id' => $e->student_number, 'last_name' => $e->last_name,
                'first_name' => $e->first_name, 'course' => $e->course, 'student' => null, 'programs' => null];

            if (!$student) {
                return $row + ['result' => 'not_in_system', 'note' => 'No student with this Student ID in the system. Nothing to do.'];
            }
            $row['student'] = trim($student->last_name.', '.$student->first_name);
            $row['student_id'] = $student->student_id;
            if ($e->last_name && EnrollmentList::normalizeName($e->last_name) !== EnrollmentList::normalizeName($student->last_name)) {
                return array_merge($row, ['result' => 'name_differs', 'note' => "In the system this Student ID is {$student->last_name}, {$student->first_name}. Check who this is."]);
            }
            $waiting = collect($approved[$student->id] ?? [])->where('enrollment_verified', false);
            if ($waiting->isNotEmpty()) {
                return array_merge($row, ['result' => 'waiting', 'programs' => $waiting->map($label)->filter()->join(', '),
                    'note' => 'Approved applicant waiting for verification: press Verify All Enrollments, then Record.']);
            }
            if (!empty($grants[$student->id])) {
                return array_merge($row, ['result' => 'grantee', 'programs' => collect($grants[$student->id])->map($label)->filter()->join(', '),
                    'note' => 'Active grantee: choose "Active grantees" in Verify All Enrollments for the start-of-semester check.']);
            }
            if (!empty($approved[$student->id])) {
                return array_merge($row, ['result' => 'verified', 'programs' => collect($approved[$student->id])->map($label)->filter()->join(', '),
                    'note' => 'Approved applicant, enrollment already verified.']);
            }

            return array_merge($row, ['result' => 'no_grant', 'note' => 'Student in the system, but no approved application or grant. Nothing to do.']);
        });

        return response()->json([
            'list' => $list,
            'summary' => $out->countBy('result')->all() + ['total' => $out->count()],
            'rows' => $out,
        ]);
    }

    public function check(Request $request)
    {
        $this->staffOnly($request);

        $data = $request->validate([
            'scope' => 'required|in:approved,grantees,both',
            'enrollment_list_id' => 'nullable|exists:enrollment_lists,id',
        ]);

        $list = isset($data['enrollment_list_id'])
            ? EnrollmentList::find($data['enrollment_list_id'])
            : EnrollmentList::latest()->latest('id')->first();

        if (!$list) {
            return response()->json(['message' => 'Upload the Registrar\'s enrollment list first.'], 422);
        }

        $entries = EnrollmentListEntry::where('enrollment_list_id', $list->id)->get()->keyBy('student_number');

        $targets = collect();

        if ($data['scope'] !== 'grantees') {
            Application::with(['student', 'scholarship:id,name'])
                ->where('status', 'approved')
                ->where('enrollment_verified', false)
                ->inOpenWork()
                ->get()
                ->each(fn ($a) => $targets->push(['kind' => 'application', 'id' => $a->id, 'student' => $a->student, 'scholarship' => $a->scholarship?->name]));
        }

        if ($data['scope'] !== 'approved') {
            ScholarRecord::with(['student', 'scholarship:id,name'])
                ->where('status', 'active')
                ->get()
                ->each(fn ($r) => $targets->push(['kind' => 'grantee', 'id' => $r->id, 'student' => $r->student, 'scholarship' => $r->scholarship?->name,
                    'currently' => $r->currently_enrolled]));
        }

        $rows = $targets->map(function ($t) use ($entries) {
            $student = $t['student'];
            $entry = $entries[EnrollmentList::normalizeId($student?->student_id)] ?? null;

            if (!$entry) {
                $result = 'not_enrolled';
                $note = 'Student ID not on the Registrar\'s list.';
            } elseif ($entry->last_name && EnrollmentList::normalizeName($entry->last_name) !== EnrollmentList::normalizeName($student?->last_name)) {
                $result = 'manual';
                $note = "Student ID found, but the Registrar's list says \"{$entry->last_name}, {$entry->first_name}\". Check who this is.";
            } else {
                $result = 'enrolled';
                $note = 'Found on the Registrar\'s list'.($entry->course ? " ({$entry->course})" : '').'.';
            }

            return [
                'kind' => $t['kind'],
                'id' => $t['id'],
                'student_id' => $student?->student_id,
                'student' => trim(($student?->last_name ?? '').', '.($student?->first_name ?? ''), ', '),
                'scholarship' => $t['scholarship'],
                'result' => $result,
                'note' => $note,
                'currently' => $t['currently'] ?? null,
            ];
        })->sortBy(fn ($r) => [$r['result'], $r['student']])->values();

        return response()->json([
            'list' => $list->load('uploader:id,name'),
            'summary' => [
                'enrolled' => $rows->where('result', 'enrolled')->count(),
                'not_enrolled' => $rows->where('result', 'not_enrolled')->count(),
                'manual' => $rows->where('result', 'manual')->count(),
            ],
            'rows' => $rows,
        ]);
    }

    public function record(Request $request)
    {
        $this->staffOnly($request);

        $data = $request->validate([
            'enrollment_list_id' => 'required|exists:enrollment_lists,id',
            'items' => 'required|array|min:1|max:5000',
            'items.*.kind' => 'required|in:application,grantee',
            'items.*.id' => 'required|integer',
            'items.*.enrolled' => 'required|boolean',
        ]);

        $list = EnrollmentList::find($data['enrollment_list_id']);
        $note = "Checked against the Registrar's list {$list->file_name} ({$list->period}).";
        $saved = ['enrolled' => 0, 'not_enrolled' => 0];
        $skipped = 0;

        DB::transaction(function () use ($data, $note, $request, $list, &$saved, &$skipped) {
            foreach ($data['items'] as $item) {
                $enrolled = (bool) $item['enrolled'];

                if ($item['kind'] === 'application') {
                    $application = Application::find($item['id']);
                    if (!$application || $application->status !== 'approved') {
                        $skipped++;
                        continue;
                    }
                    EnrollmentController::record($application, $enrolled, $note, $request->user(), 'registrar_list', $list->period);
                } else {
                    $record = ScholarRecord::with('student:id,first_name,last_name', 'scholarship:id,name')->find($item['id']);
                    if (!$record || $record->status !== 'active') {
                        $skipped++;
                        continue;
                    }
                    $record->update(['currently_enrolled' => $enrolled, 'enrollment_period' => $list->period]);
                    ActivityLog::record($request->user(), $enrolled ? 'enrollment.verified' : 'enrollment.not_enrolled',
                        ($enrolled ? 'Grantee still enrolled: ' : 'Grantee NOT enrolled this term: ')
                        .trim($record->student?->first_name.' '.$record->student?->last_name).' ('.$record->scholarship?->name.'). '.$note, $record);
                }

                $saved[$enrolled ? 'enrolled' : 'not_enrolled']++;
            }
        });

        return response()->json([
            'message' => "Saved: {$saved['enrolled']} enrolled, {$saved['not_enrolled']} not enrolled."
                .($skipped ? " {$skipped} skipped (changed meanwhile)." : ''),
            'saved' => $saved,
            'skipped' => $skipped,
        ]);
    }
}
