<?php

namespace App\Http\Controllers;

use App\Models\ExternalGrantee;
use App\Models\Student;
use App\Support\EligibilityEngine;
use Illuminate\Http\Request;

/** The centralized scholarship data bank: search a student, see full history. */
class DataBankController extends Controller
{
    private function staffOnly(Request $request)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return null;
    }

    /*
    | GET /staff/data-bank
    | q            words that must all match the student ID, a name or the course
    | college, year_level, sex
    | standing     active (holds a scholarship now) | former (held one before)
    |              | applied (applied, never a grantee) | none (never applied)
    |              | external (holds a scholarship from an outside party)
    | external_program  holds this outside scholarship (External Grantees)
    | scholarship_id  applied to, or held, this program
    | school_year, semester   has an application or a scholar record in that period
    | processing_batch_id     has an application or a scholar record in that batch
    | application_status      has an application with that status (e.g. rejected, needs_action)
    | scholar_status          has a scholar record with that status (active, inactive, completed)
    | page, per_page  "Show more" paging (25 per page, alphabetical by last name)
    | export=1     every match at once (for the CSV export, up to 5000)
    */
    public function search(Request $request)
    {
        if ($denied = $this->staffOnly($request)) {
            return $denied;
        }

        $filters = $request->validate([
            'q' => 'nullable|string|max:200',
            'college' => 'nullable|string|max:255',
            'year_level' => 'nullable|string|max:255',
            'sex' => 'nullable|in:Female,Male',
            'standing' => 'nullable|in:active,former,applied,none,external',
            'external_program' => 'nullable|string|max:255',
            'scholarship_id' => 'nullable|integer',
            'school_year' => ['nullable', 'regex:/^\d{4}-\d{4}$/'],
            'semester' => 'nullable|in:'.implode(',', \App\Models\Setting::SEMESTERS),
            'processing_batch_id' => 'nullable|integer',
            'application_status' => 'nullable|in:draft,submitted,under_review,needs_action,complete,approved,rejected',
            'scholar_status' => 'nullable|in:active,inactive,completed',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:5|max:100',
            'export' => 'nullable|boolean',
        ]);

        // Every word typed must match the student ID, a name or the course.
        // "juan student" finds Juan ... Student. Works on MySQL and SQLite.
        $words = array_filter(preg_split('/\s+/', trim((string) ($filters['q'] ?? ''))));

        $query = Student::query()
            ->when($words, function ($query) use ($words) {
                foreach ($words as $word) {
                    $like = '%'.$word.'%';
                    $digits = preg_replace('/\D/', '', $word);
                    $query->where(function ($w) use ($like, $digits) {
                        $w->where('student_id', 'like', $like)
                            ->when(strlen($digits) >= 4, fn ($d) => $d->orWhereRaw("replace(student_id, '-', '') like ?", ['%'.$digits.'%']))
                            ->orWhere('first_name', 'like', $like)
                            ->orWhere('middle_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('course', 'like', $like);
                    });
                }
            })
            ->when($filters['college'] ?? null, fn ($q, $v) => $q->where('college', $v))
            ->when($filters['year_level'] ?? null, fn ($q, $v) => $q->where('year_level', $v))
            ->when($filters['sex'] ?? null, fn ($q, $v) => $q->where('sex', $v))
            ->when($filters['scholarship_id'] ?? null, fn ($q, $id) => $q->where(fn ($w) => $w
                ->whereHas('applications', fn ($a) => $a->where('scholarship_id', $id))
                ->orWhereHas('scholarRecords', fn ($r) => $r->where('scholarship_id', $id))))
            ->when($filters['school_year'] ?? null, fn ($q, $year) => $q->where(fn ($w) => $w
                ->whereHas('applications', fn ($a) => $a->where('school_year', $year))
                ->orWhereHas('scholarRecords', fn ($r) => $r->where('school_year', $year))))
            ->when($filters['semester'] ?? null, fn ($q, $semester) => $q->where(fn ($w) => $w
                ->whereHas('applications', fn ($a) => $a->where('semester', $semester))
                ->orWhereHas('scholarRecords', fn ($r) => $r->where('semester', $semester))))
            ->when($filters['processing_batch_id'] ?? null, fn ($q, $id) => $q->where(fn ($w) => $w
                ->whereHas('applications', fn ($a) => $a->where('processing_batch_id', $id))
                ->orWhereHas('scholarRecords', fn ($r) => $r->where('processing_batch_id', $id))))
            ->when($filters['application_status'] ?? null, fn ($q, $status) => $q->whereHas('applications', fn ($a) => $a->where('status', $status)))
            ->when($filters['scholar_status'] ?? null, fn ($q, $status) => $q->whereHas('scholarRecords', fn ($r) => $r->where('status', $status)))
            ->when($filters['external_program'] ?? null, fn ($q, $name) => $q->whereHas('externalGrantees',
                fn ($e) => $e->where('program_name', $name)->counting()))
            ->when($filters['standing'] ?? null, function ($q, $standing) {
                $submitted = fn ($a) => $a->where('status', '!=', 'draft');
                $external = fn ($e) => $e->counting();
                match ($standing) {
                    'external' => $q->whereHas('externalGrantees', $external),
                    'active' => $q->whereHas('scholarRecords', fn ($r) => $r->where('status', 'active')),
                    'former' => $q->whereHas('scholarRecords')
                        ->whereDoesntHave('scholarRecords', fn ($r) => $r->where('status', 'active')),
                    'applied' => $q->whereHas('applications', $submitted)->whereDoesntHave('scholarRecords'),
                    'none' => $q->whereDoesntHave('applications', $submitted)->whereDoesntHave('scholarRecords'),
                };
            });

        $total = (clone $query)->count();
        $export = (bool) ($filters['export'] ?? false);
        $perPage = $export ? 5000 : (int) ($filters['per_page'] ?? 25);
        $page = $export ? 1 : (int) ($filters['page'] ?? 1);

        $students = $query
            ->with(['user:id,email', 'applications:id,student_id,scholarship_id,status', 'applications.scholarship:id,name',
                'scholarRecords:id,student_id,scholarship_id,school_year,status', 'scholarRecords.scholarship:id,name',
                'externalGrantees' => fn ($e) => $e->counting()
                    ->select('id', 'student_id', 'program_name', 'agency', 'status')])
            ->orderBy('last_name')->orderBy('first_name')->orderBy('student_id')
            ->skip(($page - 1) * $perPage)->take($perPage)
            ->get()
            ->each(fn ($s) => $s->setAttribute('has_active_scholarship', $s->scholarRecords->where('status', 'active')->isNotEmpty()));

        $response = [
            'data' => $students,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => !$export && $page * $perPage < $total,
        ];

        // Choices for the filter dropdowns (first page only).
        if ($page === 1) {
            $response['options'] = [
                'colleges' => Student::query()->whereNotNull('college')->distinct()->orderBy('college')->pluck('college'),
                'year_levels' => Student::query()->whereNotNull('year_level')->distinct()->orderBy('year_level')->pluck('year_level'),
                'external_programs' => ExternalGrantee::query()->counting()
                    ->distinct()->orderBy('program_name')->pluck('program_name'),
                'school_years' => \App\Models\Application::query()->whereNotNull('school_year')->distinct()
                    ->orderByDesc('school_year')->pluck('school_year'),
                'semesters' => \App\Models\Setting::SEMESTERS,
                'batches' => \App\Models\ProcessingBatch::with('scholarship:id,name,short_name')
                    ->orderByDesc('id')->get(['id', 'scholarship_id', 'name', 'status']),
            ];
        }

        return response()->json($response);
    }

    public function show(Request $request, $id)
    {
        if ($denied = $this->staffOnly($request)) {
            return $denied;
        }

        $student = Student::with([
            'user', 'applications.scholarship', 'applications.documents.validationResult',
            'applications.processingBatch:id,name,status',
            // processing history: every status change, enrollment check and tagging, with who did it
            'applications.statusLogs.changer:id,name',
            'scholarRecords.scholarship', 'scholarRecords.payrollRecords', 'scholarRecords.processingBatch:id,name,status',
            'externalGrantees' => fn ($e) => $e->where('match_status', 'matched')->latest(),
            'externalGrantees.upload:id,file_name,created_at',
            'eligibilityVerifier:id,name',
        ])->findOrFail($id);

        $student->setAttribute('has_active_scholarship', $student->scholarRecords->where('status', 'active')->isNotEmpty()
            || $student->externalGrantees->contains(fn ($e) => $e->match_status === 'matched' && $e->status === 'active' && !$e->isPastSchoolYear()));
        $student->setAttribute('eligibility_verified', EligibilityEngine::dataVerified($student));

        return response()->json($student);
    }
}
