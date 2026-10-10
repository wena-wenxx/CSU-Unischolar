<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ApplicationStatusLog;
use App\Models\ScholarRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Auto-Review (staff)
|--------------------------------------------------------------------------
| Sorts every submitted / under-review application into three lists.
| It only SUGGESTS: nothing changes until a staff member presses a button.
|   ready       every required document uploaded, AI-checked with no flag,
|               none expired, student holds no other active scholarship
|               -> "Forward selected to the agency"
|   manual      something for a person to look at (AI flag, AI could not
|               read the file, not AI-checked yet, expired document)
|   incomplete  a required document is missing, or the student already
|               holds another active scholarship -> "Send back (Needs
|               action)" with a pre-written remark staff can edit
| The agency still makes the decision (recorded later in Approved Lists).
*/
class ReviewQueueController extends Controller
{
    private function staffOnly(Request $request): void
    {
        if ($request->user()->role !== 'staff') {
            abort(response()->json(['message' => 'Only OAS staff can review applications.'], 403));
        }
    }

    // POST /staff/auto-review   Body: scholarship_id (optional)
    public function run(Request $request)
    {
        $this->staffOnly($request);

        $data = $request->validate(['scholarship_id' => 'nullable|exists:scholarships,id']);

        $applications = Application::with([
            'student', 'scholarship:id,name,provider', 'scholarship.requirements', 'documents.validationResult', 'documents.requirement:id,name',
        ])
            ->whereIn('status', ['submitted', 'under_review'])
            ->when($data['scholarship_id'] ?? null, fn ($q, $id) => $q->where('scholarship_id', $id))
            ->orderBy('submitted_at')
            ->get();

        $activeGrants = ScholarRecord::with('scholarship:id,name')
            ->where('status', 'active')
            ->whereIn('student_id', $applications->pluck('student_id'))
            ->get()
            ->groupBy('student_id');

        $rows = $applications->map(function (Application $application) use ($activeGrants) {
            $blocking = [];
            $review = [];

            if ($missing = $application->missingRequirementNames()) {
                $blocking[] = 'Missing: '.implode(', ', $missing);
            }

            $other = ($activeGrants[$application->student_id] ?? collect())
                ->firstWhere(fn ($r) => $r->scholarship_id !== $application->scholarship_id);
            if ($other) {
                $blocking[] = "Already an active grantee of {$other->scholarship?->name} (one active scholarship only)";
            }

            $required = $application->scholarship->requirements->where('is_required', true)->pluck('id')->all();

            foreach ($application->documents as $document) {
                if (!in_array((int) $document->scholarship_requirement_id, array_map('intval', $required), true)) {
                    continue;
                }
                $name = $document->typeName();

                if ($document->status === 'flagged') {
                    $first = trim(strtok((string) $document->validationResult?->flags, "\n;")) ?: 'possible problem';
                    $review[] = "AI flagged {$name}: {$first}";
                } elseif ($document->status === 'needs_review') {
                    $review[] = "AI could not read {$name}";
                } elseif ($document->status !== 'validated') {
                    $review[] = "{$name} not checked by the AI yet";
                }

                if ($document->isExpired()) {
                    $review[] = "{$name} expired on ".$document->expiresAt()?->format('M j, Y');
                }
            }

            $group = $blocking ? 'incomplete' : ($review ? 'manual' : 'ready');

            $remarks = null;
            if ($group === 'incomplete') {
                $remarks = $missing
                    ? 'Please upload the following required document(s), then submit again: '.implode(', ', $missing).'.'
                    : 'You already hold an active scholarship. Only one active scholarship is allowed; please visit the OAS.';
            }

            return [
                'id' => $application->id,
                'student_id' => $application->student?->student_id,
                'student' => trim(($application->student?->last_name ?? '').', '.($application->student?->first_name ?? ''), ', '),
                'scholarship' => $application->scholarship?->name,
                'provider' => $application->scholarship?->provider,
                'status' => $application->status,
                'submitted_at' => $application->submitted_at,
                'group' => $group,
                'reasons' => array_merge($blocking, $review),
                'suggested_remarks' => $remarks,
            ];
        })->values();

        return response()->json([
            'summary' => [
                'ready' => $rows->where('group', 'ready')->count(),
                'manual' => $rows->where('group', 'manual')->count(),
                'incomplete' => $rows->where('group', 'incomplete')->count(),
            ],
            'rows' => $rows,
        ]);
    }

    // POST /staff/applications/forward   Body: ids [..]
    public function forward(Request $request)
    {
        $this->staffOnly($request);

        $ids = $request->validate(['ids' => 'required|array|min:1|max:1000', 'ids.*' => 'integer'])['ids'];

        $done = 0;
        $errors = [];

        DB::transaction(function () use ($ids, $request, &$done, &$errors) {
            foreach (Application::with('student:id,first_name,last_name', 'scholarship:id,name')->whereIn('id', $ids)->get() as $application) {
                $who = trim($application->student?->first_name.' '.$application->student?->last_name);
                $error = null;

                if (!in_array($application->status, ['submitted', 'under_review'], true)
                    || ($error = $application->staffChangeError('complete'))) {
                    $errors[] = $who.': '.($error ?? 'not waiting for review any more');
                    continue;
                }

                $previous = $application->status;
                $application->update(['status' => 'complete', 'forwarded_at' => now()]);
                ApplicationStatusLog::record($application, 'complete', null, $request->user()->id, $previous);
                $done++;
            }
        });

        if ($done) {
            ActivityLog::record($request->user(), 'application.forwarded', "Forwarded {$done} application(s) to the agency from Auto-Review.");
        }

        return response()->json([
            'message' => "{$done} application(s) forwarded to the agency.".($errors ? ' '.count($errors).' not forwarded.' : ''),
            'forwarded' => $done,
            'errors' => $errors,
        ]);
    }

    // POST /staff/applications/needs-action   Body: items [{id, remarks}]
    public function needsAction(Request $request)
    {
        $this->staffOnly($request);

        $items = $request->validate([
            'items' => 'required|array|min:1|max:1000',
            'items.*.id' => 'required|integer',
            'items.*.remarks' => 'required|string|max:5000',
        ])['items'];

        $done = 0;
        $errors = [];

        DB::transaction(function () use ($items, $request, &$done, &$errors) {
            foreach ($items as $item) {
                $application = Application::with('student:id,first_name,last_name')->find($item['id']);

                if (!$application || !in_array($application->status, ['submitted', 'under_review'], true)) {
                    $errors[] = 'Application '.$item['id'].': not waiting for review any more';
                    continue;
                }

                $previous = $application->status;
                $application->update(['status' => 'needs_action', 'remarks' => trim($item['remarks'])]);
                ApplicationStatusLog::record($application, 'needs_action', trim($item['remarks']), $request->user()->id, $previous);
                $done++;
            }
        });

        if ($done) {
            ActivityLog::record($request->user(), 'application.needs_action', "Sent {$done} application(s) back to the students (needs action) from Auto-Review.");
        }

        return response()->json([
            'message' => "{$done} application(s) sent back to the students.".($errors ? ' '.count($errors).' skipped.' : ''),
            'changed' => $done,
            'errors' => $errors,
        ]);
    }
}
