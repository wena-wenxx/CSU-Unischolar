<?php

namespace App\Http\Controllers;

use App\Models\AgencyListUpload;
use App\Models\Application;
use App\Models\ApplicationStatusLog;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Agency approved lists
|--------------------------------------------------------------------------
| After OAS forwards applications, the agency (CHED, DOST, LGU, foundation)
| sends back the list of students it approved. Staff upload that list as a
| CSV file; the browser reads it and lets staff say which column holds the
| Student ID. This controller receives the rows and, for each student:
|
|   application found, still in process  -> status "approved" (by the agency)
|   application already approved         -> nothing changes ("already approved")
|   no application for this program      -> an approved application is created
|                                           (remark: "Added from agency list")
|   student ID not in the system         -> "unmatched"
|   rejected / draft / other active grant-> "error" with the reason; nothing changes
|
| It never creates an ACTIVE scholar record directly: the student still goes
| through enrollment verification and grantee tagging, as in the OAS workflow.
*/
class AgencyListController extends Controller
{
    private function staffOnly(Request $request): void
    {
        if ($request->user()->role !== 'staff') {
            abort(response()->json(['message' => 'Only OAS staff can upload agency lists.'], 403));
        }
    }

    public function index(Request $request)
    {
        $this->staffOnly($request);

        return response()->json(
            AgencyListUpload::with(['scholarship:id,name', 'uploader:id,name'])
                ->latest()
                ->limit(30)
                ->get()
        );
    }

    public function store(Request $request)
    {
        $this->staffOnly($request);

        $data = $request->validate([
            'scholarship_id' => 'required|exists:scholarships,id',
            'agency_name' => 'required|string|max:255',
            'file_name' => 'required|string|max:255',
            'rows' => 'required|array|min:1|max:5000',
            'rows.*.row' => 'required|integer|min:1',
            'rows.*.student_id' => 'nullable|string|max:50',
            'rows.*.approval_date' => 'nullable|string|max:50',
        ]);

        $scholarship = Scholarship::findOrFail($data['scholarship_id']);
        $staffId = $request->user()->id;

        $counts = ['approved' => 0, 'created' => 0, 'already' => 0, 'unmatched' => 0, 'error' => 0];
        $details = [];
        $seen = [];

        DB::transaction(function () use ($data, $scholarship, $staffId, &$counts, &$details, &$seen) {
            foreach ($data['rows'] as $row) {
                $result = $this->processRow($row, $scholarship, $staffId, $data, $seen);
                $counts[$result['result']]++;
                $details[] = $result;
            }
        });

        $upload = AgencyListUpload::create([
            'scholarship_id' => $scholarship->id,
            'agency_name' => $data['agency_name'],
            'file_name' => $data['file_name'],
            'uploaded_by' => $staffId,
            'total_rows' => count($data['rows']),
            'approved_count' => $counts['approved'],
            'created_count' => $counts['created'],
            'already_count' => $counts['already'],
            'unmatched_count' => $counts['unmatched'],
            'error_count' => $counts['error'],
            'details' => $details,
        ]);

        $matched = $counts['approved'] + $counts['created'] + $counts['already'];

        return response()->json([
            'message' => "Matched {$matched} student(s), {$counts['unmatched']} unmatched, {$counts['error']} error(s).",
            'upload' => $upload->load(['scholarship:id,name', 'uploader:id,name']),
        ], 201);
    }

    private function processRow(array $row, Scholarship $scholarship, int $staffId, array $data, array &$seen): array
    {
        $line = ['row' => $row['row'], 'student_id' => trim((string) ($row['student_id'] ?? ''))];
        $schoolId = strtoupper($line['student_id']);

        if ($schoolId === '') {
            return $line + ['result' => 'error', 'note' => 'No Student ID in this row.'];
        }

        if (isset($seen[$schoolId])) {
            return $line + ['result' => 'error', 'note' => "Duplicate of row {$seen[$schoolId]}; skipped."];
        }
        $seen[$schoolId] = $row['row'];

        $student = Student::whereRaw('upper(student_id) = ?', [$schoolId])->first();

        if (!$student) {
            return $line + ['result' => 'unmatched', 'note' => 'No student with this Student ID in the system.'];
        }

        $line['student'] = trim("{$student->first_name} {$student->last_name}");

        $otherGrant = ScholarRecord::with('scholarship:id,name')
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->where('scholarship_id', '!=', $scholarship->id)
            ->first();

        if ($otherGrant) {
            return $line + ['result' => 'error', 'note' => "Already an active grantee of {$otherGrant->scholarship->name} (one active scholarship rule). Not changed; check with the agency."];
        }

        $approvalNote = $this->approvalNote($row['approval_date'] ?? null, $data['agency_name'], $data['file_name']);

        $application = Application::where('student_id', $student->id)
            ->where('scholarship_id', $scholarship->id)
            ->first();

        if (!$application) {
            $application = Application::create([
                'student_id' => $student->id,
                'scholarship_id' => $scholarship->id,
                'status' => 'approved',
                'remarks' => 'Added from agency list. '.$approvalNote,
            ]);

            ApplicationStatusLog::record($application, 'approved', 'Added from the agency\'s approved list. '.$approvalNote, $staffId, null);

            return $line + ['result' => 'created', 'note' => 'No application in the system; an approved application was created. Verify enrollment next.'];
        }

        if ($application->status === 'approved') {
            return $line + ['result' => 'already', 'note' => 'Already approved; nothing changed.'];
        }

        if (in_array($application->status, ['draft', 'rejected'], true)) {
            $why = $application->status === 'draft'
                ? 'The application was never submitted (still a draft).'
                : 'The application is marked rejected in the system.';

            return $line + ['result' => 'error', 'note' => $why.' Not changed; check with the agency and update it by hand if needed.'];
        }

        $previous = $application->status;

        $application->update([
            'status' => 'approved',
            'remarks' => $approvalNote,
            'enrollment_verified' => false,
            'enrollment_verified_at' => null,
        ]);

        ApplicationStatusLog::record($application, 'approved', $approvalNote, $staffId, $previous);

        return $line + ['result' => 'approved', 'note' => "Changed from {$previous} to approved. Verify enrollment next."];
    }

    private function approvalNote(?string $date, string $agency, string $file): string
    {
        $text = "Approved by {$agency}";

        if ($date) {
            try {
                $text .= ' on '.Carbon::parse($date)->format('F j, Y');
            } catch (\Throwable) {
                $text .= " (approval date in file: {$date})";
            }
        }

        return $text." (list: {$file}).";
    }
}
