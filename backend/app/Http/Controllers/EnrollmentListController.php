<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\EnrollmentList;
use App\Models\EnrollmentListEntry;
use App\Models\ScholarRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Enrollment: Registrar list + "Verify All Enrollments"
|--------------------------------------------------------------------------
| 1. OAS uploads the Registrar's list of enrolled students (CSV, read in
|    the browser; only Student ID, last name, first name, course are sent).
| 2. "Verify All" checks every approved applicant waiting for verification
|    and/or every active grantee against the latest list, by Student ID:
|      enrolled      Student ID found and the last name matches
|      not_enrolled  Student ID not on the Registrar's list
|      manual        Student ID found but the name is different, or no list
| 3. Nothing is saved until staff press "Record": the system suggests,
|    staff confirm.
*/
class EnrollmentListController extends Controller
{
    private function staffOnly(Request $request): void
    {
        if ($request->user()->role !== 'staff') {
            abort(response()->json(['message' => 'Only OAS staff can verify enrollment.'], 403));
        }
    }

    // GET /staff/enrollment-lists
    public function index(Request $request)
    {
        $this->staffOnly($request);

        return response()->json([
            'lists' => EnrollmentList::with('uploader:id,name')->latest()->latest('id')->limit(20)->get(),
            'waiting' => Application::where('status', 'approved')->where('enrollment_verified', false)->count(),
            'grantees' => ScholarRecord::where('status', 'active')->count(),
        ]);
    }

    // POST /staff/enrollment-lists   Body: period, file_name, rows [{student_id, last_name, first_name, course}]
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

        // Keep one row per Student ID; skip empty IDs.
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

    /*
    | POST /staff/enrollment/check   Body: scope approved|grantees|both, enrollment_list_id (optional: latest)
    | Returns the suggested result for everyone; saves nothing.
    */
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

    /*
    | POST /staff/enrollment/record
    | Body: enrollment_list_id, items [{kind: application|grantee, id, enrolled: true|false}]
    */
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

        DB::transaction(function () use ($data, $note, $request, &$saved, &$skipped) {
            foreach ($data['items'] as $item) {
                $enrolled = (bool) $item['enrolled'];

                if ($item['kind'] === 'application') {
                    $application = Application::find($item['id']);
                    if (!$application || $application->status !== 'approved') {
                        $skipped++;
                        continue;
                    }
                    EnrollmentController::record($application, $enrolled, $note, $request->user());
                } else {
                    $record = ScholarRecord::with('student:id,first_name,last_name', 'scholarship:id,name')->find($item['id']);
                    if (!$record || $record->status !== 'active') {
                        $skipped++;
                        continue;
                    }
                    $record->update(['currently_enrolled' => $enrolled]);
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
