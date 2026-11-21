<?php

namespace App\Http\Controllers;

use App\Models\EnrollmentList;
use App\Models\ActivityLog;
use App\Models\AgencyListUpload;
use App\Models\Application;
use App\Models\ApplicationStatusLog;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\Student;
use App\Models\Setting;
use App\Support\ApplicationNotifier;
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
    /** Applications approved by this upload (they get an e-mail afterwards). */
    private array $approvedApplications = [];

    private ?int $uploadId = null;

    /** Every student (id, Student ID, names), loaded once per upload for lists without Student IDs. */
    private ?\Illuminate\Support\Collection $everyone = null;

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
            'campus' => 'nullable|string|max:255',
            'file_name' => 'required|string|max:255',
            'rows' => 'required|array|min:1|max:5000',
            'school_year' => ['nullable', 'regex:/^\d{4}-\d{4}$/'],
            'semester' => 'nullable|in:'.implode(',', Setting::SEMESTERS),
            'batch' => 'nullable|string|max:100',
            'reference_number' => 'nullable|string|max:255',
            'list_date' => 'nullable|date',
            'expected_count' => 'nullable|integer|min:0|max:5000',
            'expected_total' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:5000',
            'confirmed_mismatch' => 'nullable|boolean',
            'confirmed_duplicate' => 'nullable|boolean',
            'rows.*.row' => 'required|integer|min:1',
            'rows.*.student_id' => 'nullable|string|max:50',
            'rows.*.approval_date' => 'nullable|string|max:50',
            // Amounts read from a scan can be unreadable ("10,0O0.0p"): such a value is kept out of the
            // award details instead of stopping the whole list.
            'rows.*.amount' => 'nullable',
            'rows.*.control_number' => 'nullable|string|max:50',
            'rows.*.award_number' => 'nullable|string|max:255',
            'rows.*.last_name' => 'nullable|string|max:255',
            'rows.*.first_name' => 'nullable|string|max:255',
            'rows.*.middle_name' => 'nullable|string|max:255',
            'rows.*.sex' => 'nullable|string|max:20',
            'rows.*.birthdate' => 'nullable|string|max:50',
            'rows.*.program' => 'nullable|string|max:255',
            'rows.*.year_level' => 'nullable|string|max:20',
            'rows.*.email' => 'nullable|string|max:255',
            'rows.*.phone' => 'nullable|string|max:50',
            'rows.*.batch' => 'nullable|string|max:100',
            'rows.*.pwd' => 'nullable|string|max:10',
            'rows.*.total_amount' => 'nullable',
        ]);

        $expectedCount = $data['expected_count'] ?? null;
        $expectedTotal = $data['expected_total'] ?? null;
        $countMismatch = $expectedCount !== null && (int) $expectedCount !== count($data['rows']);
        $amounts = collect($data['rows'])->map(fn ($row) => is_numeric($row['amount'] ?? null) ? (float) $row['amount'] : 0)->sum();
        $amountMismatch = $expectedTotal !== null && abs($amounts - (float) $expectedTotal) > 0.01;
        if (($countMismatch || $amountMismatch) && empty($data['confirmed_mismatch'])) {
            return response()->json(['message' => 'Confirm the count or total mismatch before processing this list.'], 422);
        }

        $scholarship = Scholarship::findOrFail($data['scholarship_id']);
        $staffId = $request->user()->id;

        // The same list processed twice (same program, file and period) is almost always a mistake.
        $earlier = AgencyListUpload::where('scholarship_id', $scholarship->id)
            ->where('file_name', $data['file_name'])
            ->where('school_year', $data['school_year'] ?? null)
            ->where('semester', $data['semester'] ?? null)
            ->where('batch', $data['batch'] ?? null)
            ->latest()
            ->first();
        if ($earlier && empty($data['confirmed_duplicate'])) {
            return response()->json([
                'message' => "This list ({$data['file_name']}) was already processed for {$scholarship->name} on "
                    .$earlier->created_at->format('F j, Y g:i A').'. Process it again only if the agency sent a corrected list.',
                'duplicate_of' => $earlier->id,
            ], 409);
        }

        $counts = ['approved' => 0, 'created' => 0, 'already' => 0, 'unmatched' => 0, 'error' => 0];
        $details = [];
        $seen = [];
        $upload = null;

        DB::transaction(function () use ($data, $scholarship, $staffId, &$counts, &$details, &$seen, &$upload) {
            // The upload is saved first so every application can point to the list it came from.
            $upload = AgencyListUpload::create([
                'scholarship_id' => $scholarship->id,
                'agency_name' => $data['agency_name'],
                'campus' => $data['campus'] ?? null,
                'file_name' => $data['file_name'],
                'uploaded_by' => $staffId,
                'total_rows' => count($data['rows']),
                'approved_count' => 0, 'created_count' => 0, 'already_count' => 0,
                'unmatched_count' => 0, 'error_count' => 0,
                'details' => [],
                'school_year' => $data['school_year'] ?? null,
                'semester' => $data['semester'] ?? null,
                'batch' => $data['batch'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'list_date' => $data['list_date'] ?? null,
                'expected_count' => $data['expected_count'] ?? null,
                'expected_total' => $data['expected_total'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $this->uploadId = $upload->id;

            foreach ($data['rows'] as $row) {
                $result = $this->processRow($row, $scholarship, $staffId, $data, $seen);
                // "check" (Student ID found but the name on the list is different) changes nothing,
                // so it is counted with the rows that need attention.
                $counts[$result['result'] === 'check' ? 'error' : $result['result']]++;
                $details[] = $result;
            }

            $upload->update([
                'approved_count' => $counts['approved'],
                'created_count' => $counts['created'],
                'already_count' => $counts['already'],
                'unmatched_count' => $counts['unmatched'],
                'error_count' => $counts['error'],
                'details' => $details,
            ]);
        });

        // E-mails go out after the database work is saved.
        $emailed = 0;
        foreach ($this->approvedApplications as $application) {
            if (ApplicationNotifier::approved($application, 'agency_list')?->status !== 'failed') {
                $emailed++;
            }
        }

        $matched = $counts['approved'] + $counts['created'] + $counts['already'];

        ActivityLog::record($request->user(), 'agency_list.uploaded',
            "Uploaded approved list {$data['file_name']} for {$scholarship->name} ({$this->periodLabel($data)}): {$counts['approved']} approved, {$counts['created']} added, {$counts['unmatched']} unmatched.", $upload);

        return response()->json([
            'message' => "Matched {$matched} student(s), {$counts['unmatched']} unmatched, {$counts['error']} to check. {$emailed} approval e-mail(s) sent."
                .($counts['approved'] + $counts['created'] ? ' Next: verify their enrollment (Enrollment → Verify All Enrollments).' : ''),
            'upload' => $upload->load(['scholarship:id,name', 'uploader:id,name']),
        ], 201);
    }

    private function processRow(array $row, Scholarship $scholarship, int $staffId, array $data, array &$seen): array
    {
        $line = ['row' => $row['row'], 'student_id' => trim((string) ($row['student_id'] ?? ''))];
        foreach ([
            'control_number', 'award_number', 'last_name', 'first_name', 'middle_name',
            'sex', 'birthdate', 'program', 'year_level', 'email', 'phone', 'batch',
            'pwd', 'amount', 'total_amount',
        ] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                $line[$field] = $value;
            }
        }
        // "2026-00001", "2026 00001" and 202600001 (Excel turns the ID into a number) are the same student.
        $schoolId = EnrollmentList::normalizeId($line['student_id']);

        $byName = false;
        if ($schoolId === '') {
            // No Student ID on the list: only an exact, one-of-a-kind name match is accepted.
            $student = $this->studentByName($row);
            if ($student === false) {
                return $line + ['result' => 'error', 'note' => 'No Student ID and no name in this row.'];
            }
            if ($student === null) {
                return $line + ['result' => 'unmatched', 'note' => 'No Student ID on the list, and the name matches no student (or more than one). Find the student in Data Bank and add the Student ID.'];
            }
            $schoolId = EnrollmentList::normalizeId($student->student_id);
            $line['student_id'] = $student->student_id;
            $byName = true;
        }

        if (isset($seen[$schoolId])) {
            return $line + ['result' => 'error', 'note' => "Duplicate of row {$seen[$schoolId]}; skipped."];
        }
        $seen[$schoolId] = $row['row'];

        $student ??= Student::whereRaw("upper(replace(replace(replace(student_id, '-', ''), ' ', ''), '.', '')) = ?", [$schoolId])->first();

        if (!$student) {
            return $line + ['result' => 'unmatched', 'note' => 'No student with this Student ID in the system.'];
        }
        if ($byName) {
            $line['matched_by'] = 'name';
        }

        $line['student'] = trim("{$student->first_name} {$student->last_name}");

        // The Student ID matched. If the list also has a name, it must be the same person: a misread or
        // mistyped ID could otherwise approve the wrong student.
        $nameProblem = self::nameDiffers($row, $student);
        if ($nameProblem) {
            return $line + ['result' => 'check', 'note' => $nameProblem.' Nothing was changed: check the Student ID on the list.'];
        }

        $otherGrant = $student->otherActiveScholarship($scholarship->id);

        if ($otherGrant) {
            return $line + ['result' => 'error', 'note' => "Already an active grantee of {$otherGrant} (one active scholarship rule). Not changed; check with the agency."];
        }

        $approvalNote = $this->approvalNote($row['approval_date'] ?? null, $data['agency_name'], $data['file_name']);

        // The application of the list's school year; otherwise one still in process from before;
        // otherwise the student may already be a grantee of this program (a continuing grantee).
        $year = $data['school_year'] ?? \App\Models\ExternalGrantee::currentSchoolYear();
        $application = Application::where('student_id', $student->id)
            ->where('scholarship_id', $scholarship->id)
            ->where('school_year', $year)
            ->first()
            ?? Application::where('student_id', $student->id)
                ->where('scholarship_id', $scholarship->id)
                ->whereNotIn('status', ['approved', 'rejected', 'draft'])
                ->latest('id')
                ->first();

        if (!$application && $student->scholarRecords()->where('scholarship_id', $scholarship->id)->where('status', 'active')->exists()) {
            $latest = Application::where('student_id', $student->id)->where('scholarship_id', $scholarship->id)->latest('id')->first();
            $award = $this->awardDetails($row);
            if ($latest && $award) {
                $latest->update($award);
                $this->copyToScholarRecord($latest, $award);
            }

            return $line + ['result' => 'already', 'note' => 'Continuing grantee of this program; status not changed.'.($award ? ' Award details from the list were saved.' : '')];
        }

        $award = $this->awardDetails($row);

        if (!$application) {
            $application = Application::create([
                'student_id' => $student->id,
                'scholarship_id' => $scholarship->id,
                'school_year' => $year,
                'status' => 'approved',
                'remarks' => 'Added from agency list. '.$approvalNote,
            ] + $award);
            $this->copyToScholarRecord($application, $award);

            ApplicationStatusLog::record($application, 'approved', 'Added from the agency\'s approved list. '.$approvalNote, $staffId, null);
            $this->approvedApplications[] = $application;

            return $line + ['result' => 'created', 'note' => 'No application in the system; an approved application was created. Verify enrollment next.'];
        }

        if ($application->status === 'approved') {
            // The status stays; a newer list (e.g. the next TES billing batch) still updates the award details.
            if ($award) {
                $application->update($award);
                $this->copyToScholarRecord($application, $award);
            }

            return $line + ['result' => 'already', 'note' => 'Already approved; status not changed.'.($award ? ' Award details from the list were saved.' : '')];
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
        ] + $award);
        $this->copyToScholarRecord($application, $award);

        ApplicationStatusLog::record($application, 'approved', $approvalNote, $staffId, $previous);
        $this->approvedApplications[] = $application;

        // The agency's decision is recorded, but OAS should know when it had not finished its own check.
        $early = in_array($previous, ['submitted', 'under_review', 'needs_action'], true)
            ? " Note: OAS had not finished checking this application's documents (it was {$previous}); check them."
            : '';

        return $line + ['result' => 'approved', 'note' => "Changed from {$previous} to approved. Verify enrollment next.".$early
            .(!empty($line['matched_by']) ? ' Matched by name (no Student ID on the list): check that it is the right student.' : '')];
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

    /** Control number, award number, amount and batch from the list row (only the ones that are there). */
    private function awardDetails(array $row): array
    {
        $amount = collect([$row['total_amount'] ?? null, $row['amount'] ?? null])->first(fn ($v) => is_numeric($v));

        return array_filter([
            'agency_control_number' => isset($row['control_number']) ? mb_substr(trim((string) $row['control_number']), 0, 20) : null,
            'agency_award_number' => isset($row['award_number']) ? mb_substr(trim((string) $row['award_number']), 0, 60) : null,
            'agency_amount' => is_numeric($amount) && (float) $amount >= 0 ? round((float) $amount, 2) : null,
            'agency_batch' => isset($row['batch']) ? mb_substr(trim((string) $row['batch']), 0, 30) : null,
        ], fn ($value) => $value !== null && $value !== '') + ($this->uploadId ? ['agency_list_upload_id' => $this->uploadId] : []);
    }

    /** A student who is already a grantee also gets the new details on the scholar record. */
    private function copyToScholarRecord(Application $application, array $award): void
    {
        $values = array_filter([
            'control_number' => $award['agency_control_number'] ?? null,
            'award_number' => $award['agency_award_number'] ?? null,
            'award_amount' => $award['agency_amount'] ?? null,
            'award_batch' => $award['agency_batch'] ?? null,
        ], fn ($value) => $value !== null);

        if ($values) {
            ScholarRecord::where('student_id', $application->student_id)
                ->where('scholarship_id', $application->scholarship_id)
                ->update($values);
        }
    }

    /**
     * null when the name on the list fits the student, otherwise a sentence saying what differs.
     * Accents, capitals, dots and the order of given names do not matter (ÑUÑEZ = Nunez).
     */
    public static function nameDiffers(array $row, Student $student): ?string
    {
        $listLast = self::plain($row['last_name'] ?? '');
        $listFirst = self::plain($row['first_name'] ?? '');
        if ($listLast === '' && $listFirst === '') {
            return null;            // the list has no name: only the Student ID can be checked
        }

        $last = self::plain($student->last_name);
        $first = self::plain($student->first_name);

        $lastOk = $listLast === '' || self::similar($listLast, $last) >= 80;
        $firstOk = $listFirst === '' || self::similar($listFirst, $first) >= 75
            || collect(explode(' ', $listFirst))->contains(fn ($w) => strlen($w) > 2 && str_contains(' '.$first.' ', ' '.$w.' '));

        if ($lastOk && $firstOk) {
            return null;
        }

        $onList = trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? ''));

        return "The Student ID belongs to {$student->first_name} {$student->last_name}, but the list says \"{$onList}\".";
    }

    /**
     * The one student whose last and first name are exactly the ones on the list (accents, capitals and
     * dots ignored). null = none or more than one; false = the row has no name.
     */
    private function studentByName(array $row): Student|false|null
    {
        $last = self::plain($row['last_name'] ?? '');
        $first = self::plain($row['first_name'] ?? '');
        if ($last === '' || $first === '') {
            return ($last === '' && $first === '') ? false : null;
        }
        // Loaded once per upload; names are compared without accents (ÑUÑEZ = NUNEZ).
        $this->everyone ??= Student::get(['id', 'student_id', 'first_name', 'last_name']);
        $candidates = $this->everyone
            ->filter(fn (Student $s) => self::plain($s->last_name) === $last
                && (self::plain($s->first_name) === $first || str_starts_with(self::plain($s->first_name).' ', $first.' ')
                    || str_starts_with($first.' ', self::plain($s->first_name).' ')))
            ->values();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private static function plain(?string $text): string
    {
        $text = \Illuminate\Support\Str::ascii((string) $text);
        $text = strtoupper(preg_replace('/[^A-Za-z ]+/', ' ', $text));

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private static function similar(string $a, string $b): float
    {
        if ($a === $b) {
            return 100.0;
        }
        similar_text($a, $b, $percent);

        return $percent;
    }

    private function periodLabel(array $data): string
    {
        $semester = $data['semester'] ?? '';
        $schoolYear = $data['school_year'] ?? '';
        $batch = $data['batch'] ?? '';
        return trim(implode(' ', array_filter([$semester, $schoolYear ? 'AY '.$schoolYear : '', $batch])));
    }
}
