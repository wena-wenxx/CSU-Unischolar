<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Document;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\Student;
use App\Services\AiValidationService;
use Illuminate\Http\Request;

/**
 * Application status meanings:
 *   draft         - student started it, not yet submitted
 *   submitted     - student finished and submitted it
 *   under_review  - OAS staff is reviewing it
 *   needs_action  - something needs to be corrected by the student
 *   complete      - documents/info passed OAS review, waiting on the external agency
 *   approved      - the external agency approved this applicant
 *   rejected      - the external agency (or OAS) rejected this applicant
 */
class ApplicationController extends Controller
{
    private function isStaff(Request $request): bool
    {
        return $request->user()->role === 'staff';
    }

    private function studentOf(Request $request): ?Student
    {
        return Student::where('user_id', $request->user()->id)->first();
    }

    private function withMissing($application)
    {
        $application->setAttribute('missing_requirements', $application->missingRequirementNames());

        return $application;
    }

    // ---------------------------------------------------------------
    // STUDENT: start an application (status = draft)
    // ---------------------------------------------------------------
    public function store(Request $request)
    {
        if ($request->user()->role !== 'student') {
            return response()->json(['message' => 'Only students can apply'], 403);
        }

        $request->validate(['scholarship_id' => 'required|exists:scholarships,id']);

        $student = $this->studentOf($request);
        if (! $student) {
            return response()->json(['message' => 'Student profile not found'], 404);
        }

        $scholarship = Scholarship::findOrFail($request->scholarship_id);
        if ($scholarship->status !== 'active') {
            return response()->json(['message' => 'This scholarship is not open for applications.'], 422);
        }

        // BUSINESS RULE: only ONE active scholarship at a time
        $hasActive = ScholarRecord::where('student_id', $student->id)->where('status', 'active')->exists();
        if ($hasActive) {
            return response()->json(['message' => 'You already have an active scholarship. Only one is allowed at a time.'], 422);
        }

        $already = Application::where('student_id', $student->id)->where('scholarship_id', $scholarship->id)->exists();
        if ($already) {
            return response()->json(['message' => 'You already started/applied to this scholarship.'], 422);
        }

        $application = Application::create([
            'student_id' => $student->id,
            'scholarship_id' => $scholarship->id,
            'status' => 'draft',
            'submitted_at' => null,
        ]);

        return response()->json($application, 201);
    }

    // STUDENT: submit a draft (blocked until all required documents are uploaded)
    public function submit(Request $request, $id)
    {
        $application = Application::with('scholarship.requirements', 'documents')->findOrFail($id);

        $student = $this->studentOf($request);
        if (! $student || $student->id !== $application->student_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($application->status !== 'draft') {
            return response()->json(['message' => 'Only draft applications can be submitted.'], 422);
        }

        $missing = $application->missingRequirementNames();
        if (! empty($missing)) {
            return response()->json([
                'message' => 'Required documents are incomplete.',
                'missing_requirements' => $missing,
            ], 422);
        }

        $application->update(['status' => 'submitted', 'submitted_at' => now()]);

        return response()->json([
            'message' => 'Application submitted successfully.',
            'application' => $application->fresh(),
        ]);
    }

    // STUDENT: my applications (with requirements + uploaded documents)
    public function myApplications(Request $request)
    {
        $student = $this->studentOf($request);
        if (! $student) {
            return response()->json(['message' => 'Student profile not found'], 404);
        }

        $apps = Application::with(['scholarship.requirements', 'documents.validationResult'])
            ->where('student_id', $student->id)->orderBy('id', 'desc')->get()
            ->each(fn ($a) => $this->withMissing($a));

        return response()->json($apps);
    }

    // ---------------------------------------------------------------
    // STAFF: list all applications (optional ?status=)
    // ---------------------------------------------------------------
    public function index(Request $request)
    {
        if (! $this->isStaff($request)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $apps = Application::with(['student', 'scholarship.requirements', 'documents.validationResult'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('id', 'desc')->get()
            ->each(fn ($a) => $this->withMissing($a));

        return response()->json($apps);
    }

    public function show(Request $request, $id)
    {
        $application = Application::with(['student', 'scholarship.requirements', 'documents.validationResult'])->findOrFail($id);

        if (! $this->isStaff($request)) {
            $student = $this->studentOf($request);
            if (! $student || $student->id !== $application->student_id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        return response()->json($this->withMissing($application));
    }

    // STAFF: review a submitted application
    public function review(Request $request, $id)
    {
        if (! $this->isStaff($request)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'status' => 'required|in:under_review,needs_action,complete,approved,rejected',
            'remarks' => 'nullable|string',
        ]);

        $application = Application::findOrFail($id);
        $application->update($data);

        return response()->json([
            'message' => 'Application review updated successfully.',
            'application' => $application->fresh(['student', 'scholarship', 'documents.validationResult']),
        ]);
    }

    // ---------------------------------------------------------------
    // Upload a document (student for own application) + run the AI check
    // ---------------------------------------------------------------
    public function uploadDocument(Request $request, $id)
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png',
            'scholarship_requirement_id' => 'nullable|exists:scholarship_requirements,id',
            'document_type' => 'nullable|string|max:255',
        ]);

        $application = Application::with('student', 'scholarship.requirements')->findOrFail($id);

        if (! $this->isStaff($request)) {
            $student = $this->studentOf($request);
            if (! $student || $student->id !== $application->student_id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        if (in_array($application->status, ['approved', 'rejected'])) {
            return response()->json(['message' => 'Documents cannot be uploaded after the application has been finalized.'], 422);
        }

        // the requirement must actually belong to this application's scholarship
        if ($request->scholarship_requirement_id) {
            $belongs = $application->scholarship->requirements()
                ->where('id', $request->scholarship_requirement_id)->exists();
            if (! $belongs) {
                return response()->json(['message' => 'This requirement does not belong to the application scholarship.'], 422);
            }
        }

        $file = $request->file('file');
        $path = $file->store('documents', 'public');

        $document = Document::create([
            'application_id' => $application->id,
            'scholarship_requirement_id' => $request->scholarship_requirement_id,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $path,
            'document_type' => $request->document_type,
            'status' => 'uploaded',
        ]);

        // if OAS asked for corrections, a new upload puts it back in the queue
        if ($application->status === 'needs_action') {
            $application->update(['status' => 'submitted']);
        }

        $document = app(AiValidationService::class)->validate($document, $application->student, $request->document_type);

        return response()->json($document, 201);
    }

    // STAFF: run the AI check again
    public function revalidateDocument(Request $request, $id)
    {
        if (! $this->isStaff($request)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $document = Document::with(['application.student', 'requirement'])->findOrFail($id);
        $label = $document->document_type ?: optional($document->requirement)->name;
        $document = app(AiValidationService::class)->validate($document, $document->application->student, $label);

        return response()->json($document);
    }

    // STAFF: the HUMAN decision on a document (AI never decides)
    public function reviewDocument(Request $request, $id)
    {
        if (! $this->isStaff($request)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate(['status' => 'required|in:validated,flagged,needs_review']);
        $document = Document::findOrFail($id);
        $document->update($data);

        return response()->json($document->load('validationResult'));
    }
}
