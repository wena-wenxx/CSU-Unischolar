<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ApplicationStatusLog;
use App\Models\Document;
use App\Models\ScholarRecord;
use App\Models\ScholarshipRequirement;
use App\Models\Scholarship;
use App\Models\Student;
use App\Support\ApplicationNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApplicationController extends Controller
{
    public function store(Request $request)
    {
        if ($request->user()->role !== 'student') {
            return response()->json([
                'message' => 'Only students can apply.'
            ], 403);
        }

        $request->validate([
            'scholarship_id' => 'required|exists:scholarships,id',
        ]);

        $student = Student::where(
            'user_id',
            $request->user()->id
        )->first();

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.'
            ], 404);
        }

        $scholarship = Scholarship::findOrFail(
            $request->scholarship_id
        );

        if ($scholarship->isAgencyDirect() || !$scholarship->is_open) {
            return response()->json([
                'message' => $scholarship->closedReason()
            ], 422);
        }

        $holding = $student->otherActiveScholarship();

        if ($holding) {
            return response()->json([
                'message' => 'You are currently a grantee of '.$holding
                    .'. Only one active scholarship is allowed, so you cannot apply for a new one at this time.'
            ], 422);
        }

        $alreadyApplied = Application::where(
            'student_id',
            $student->id
        )
            ->where('scholarship_id', $scholarship->id)
            ->thisSchoolYear()
            ->exists();

        if ($alreadyApplied) {
            return response()->json([
                'message' => 'You already applied to this scholarship for school year '.\App\Models\ExternalGrantee::currentSchoolYear().'.'
            ], 422);
        }

        $stillOpen = Application::where('student_id', $student->id)
            ->where('scholarship_id', $scholarship->id)
            ->whereNotIn('status', ['approved', 'rejected'])
            ->first();

        if ($stillOpen) {
            return response()->json([
                'message' => "You still have an application for this scholarship from school year {$stillOpen->school_year} ({$stillOpen->status}). Open it from My Applications."
            ], 422);
        }

        $application = Application::create([
            'student_id' => $student->id,
            'scholarship_id' => $scholarship->id,
            'status' => 'draft',
        ]);

        ApplicationStatusLog::record($application, 'draft', null, $request->user()->id);
        ActivityLog::record($request->user(), 'application.started', "Started an application for {$scholarship->name}.", $application);

        return response()->json(
            $application->load('scholarship'),
            201
        );
    }

    public function submit(Request $request, $id)
    {
        if ($request->user()->role !== 'student') {
            return response()->json([
                'message' => 'Only students can submit applications.'
            ], 403);
        }

        $application = Application::with([
            'documents',
            'scholarship.requirements'
        ])->findOrFail($id);

        $student = Student::where(
            'user_id',
            $request->user()->id
        )->first();

        if (!$student || $application->student_id !== $student->id) {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        if (!in_array($application->status, ['draft', 'needs_action'])) {
            return response()->json([
                'message' => 'This application cannot be submitted in its current status. Open My Applications to see what it is waiting for.'
            ], 422);
        }

        if ($application->status === 'draft' && !$application->scholarship->is_open) {
            return response()->json([
                'message' => $application->scholarship->closedReason()
            ], 422);
        }

        $requiredRequirements = $application
            ->scholarship
            ->requirements
            ->where('is_required', true);

        foreach ($requiredRequirements as $requirement) {

            $hasDocument = $application
                ->documents
                ->where(
                    'scholarship_requirement_id',
                    $requirement->id
                )
                ->count() > 0;

            if (!$hasDocument) {
                return response()->json([
                    'message' => 'Required document is missing.',
                    'requirement' => $requirement
                ], 422);
            }
        }

        $previousStatus = $application->status;

        $application->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        ApplicationStatusLog::record($application, 'submitted', null, $request->user()->id, $previousStatus);
        ActivityLog::record($request->user(), 'application.submitted', ($previousStatus === 'needs_action' ? 'Resubmitted' : 'Submitted').' the application for '.$application->scholarship->name.'.', $application);

        return response()->json([
            'message' => 'Application submitted successfully.',
            'application' => $application->fresh()->load([
                'documents',
                'scholarship'
            ])
        ]);
    }

    public function myApplications(Request $request)
    {
        $student = Student::where(
            'user_id',
            $request->user()->id
        )->first();

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.'
            ], 404);
        }

        $applications = Application::with([
            'scholarship',
            'documents.validationResult'
        ])
            ->where('student_id', $student->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        return response()->json($applications);
    }

    public function index(Request $request)
    {
        if ($request->user()->role !== 'staff' && !$request->user()->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $statuses = array_filter(explode(',', (string) $request->query('status', '')));

        $applications = Application::query()
            ->select(['id', 'student_id', 'scholarship_id', 'school_year', 'semester', 'processing_batch_id', 'status', 'remarks', 'submitted_at', 'forwarded_at',
                'agency_submission_id', 'enrollment_verified', 'enrollment_verified_at', 'enrollment_method', 'created_at', 'updated_at'])
            ->with([
                'student:id,user_id,student_id,first_name,middle_name,last_name,sex,course,year_level,college,contact_number',
                'student.user:id,email',
                'scholarship:id,name,short_name,provider,category,amount,status,application_mode',
                'scholarship.requirements:id,scholarship_id,name,is_required',
                'documents:id,application_id,scholarship_requirement_id,status,staff_verified_at',
                'latestLog',
                'processingBatch:id,name,status',
                'agencySubmission:id,sent_on,sent_by_way,agency_name',
            ])
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($request->integer('processing_batch_id') ?: null, fn ($q, $id) => $q->where('processing_batch_id', $id))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->each(function (Application $application) {
                $covered = $application->documents->pluck('scholarship_requirement_id')->filter()->all();
                $application->setAttribute('missing_requirements', $application->scholarship?->requirements
                    ->where('is_required', true)
                    ->reject(fn ($r) => in_array($r->id, $covered))
                    ->pluck('name')->values() ?? []);
                $application->setAttribute('flagged_count', $application->documents->where('status', 'flagged')->whereNull('staff_verified_at')->count());
                $application->setAttribute('documents_count', $application->documents->count());
                $application->setRelation('documents', collect());
                $application->makeHidden('documents');
            });

        $applications->pluck('scholarship')->filter()->unique('id')->each->makeHidden('requirements');

        return response()->json($applications);
    }

    public function version(Request $request)
    {
        if (!$request->user()->isOffice()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $apps = Application::query()->selectRaw('count(*) as c, max(updated_at) as u')->first();
        $docs = \App\Models\Document::query()->whereNotNull('application_id')->selectRaw('count(*) as c, max(updated_at) as u')->first();

        return response()->json(['version' => "{$apps->c}|{$apps->u}|{$docs->c}|{$docs->u}"]);
    }

    public function show(Request $request, $id)
    {
        $application = Application::with([
            'student',
            'scholarship.requirements',
            'documents.validationResult',
            'documents.staffVerifier:id,name',
            'documents.requirement',
            'statusLogs',
        ])->findOrFail($id);

        if ($request->user()->role !== 'staff') {

            $student = Student::where(
                'user_id',
                $request->user()->id
            )->first();

            if (!$student || (int) $application->student_id !== (int) $student->id) {
                return response()->json([
                    'message' => 'Unauthorized.'
                ], 403);
            }

            return response()->json($application);
        }

        $application->setAttribute('next_statuses', $application->staffNextStatuses());
        $application->setAttribute('missing_requirements', $application->missingRequirementNames());

        $application->documents->each(function ($document) {
            $document->setAttribute('expires_at', $document->expiresAt()?->toDateString());
            $document->setAttribute('is_expired', $document->isExpired());
        });

        $application->student?->loadMissing('user:id,email');

        $application->setAttribute('other_applications', Application::with('scholarship:id,name')
            ->where('student_id', $application->student_id)
            ->where('id', '!=', $application->id)
            ->latest()
            ->get(['id', 'scholarship_id', 'status', 'submitted_at'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'scholarship' => $a->scholarship?->name,
                'status' => $a->status,
                'submitted_at' => $a->submitted_at,
            ]));

        $application->setAttribute('scholar_records', ScholarRecord::with('scholarship:id,name')
            ->where('student_id', $application->student_id)
            ->get(['id', 'scholarship_id', 'status', 'grantee_tagged_at'])
            ->map(fn ($r) => [
                'scholarship' => $r->scholarship?->name,
                'status' => $r->status,
                'tagged_at' => $r->grantee_tagged_at,
            ]));

        return response()->json($application);
    }

    public function uploadDocument(Request $request, $id)
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png',
            'scholarship_requirement_id' => 'nullable|exists:scholarship_requirements,id',
            'document_type' => 'nullable|string|max:255',
        ]);

        $application = Application::findOrFail($id);

        if ($request->user()->role === 'admin') {
            return response()->json(['message' => 'Only the student or OAS staff can add documents to an application.'], 403);
        }

        if ($request->user()->role === 'student') {

            $student = Student::where(
                'user_id',
                $request->user()->id
            )->first();

            if (
                !$student ||
                $student->id !== $application->student_id
            ) {
                return response()->json([
                    'message' => 'Unauthorized.'
                ], 403);
            }

            if (
                !in_array(
                    $application->status,
                    ['draft', 'needs_action']
                )
            ) {
                return response()->json([
                    'message' => 'Documents cannot be uploaded at this stage (OAS is already checking or has decided). To send a newer copy, use Contact OAS.'
                ], 422);
            }
        }

        $file = $request->file('file');

        if ($request->scholarship_requirement_id) {
            $previous = Document::where('application_id', $application->id)
                ->where('scholarship_requirement_id', $request->scholarship_requirement_id)
                ->get();

            foreach ($previous as $old) {
                $old->delete();
                Document::deleteFileIfUnused($old->file_path);
            }
        }

        $path = $file->store('documents', Document::DISK);

        $requirementName = $request->scholarship_requirement_id
            ? ScholarshipRequirement::whereKey($request->scholarship_requirement_id)->value('name')
            : null;

        $document = Document::create([
            'student_id' => $application->student_id,
            'application_id' => $application->id,
            'scholarship_requirement_id' =>
                $request->scholarship_requirement_id,
            'original_filename' =>
                $file->getClientOriginalName(),
            'file_path' => $path,
            'document_type' =>
                $request->document_type ?: $requirementName,
            'status' => 'uploaded',
        ]);

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'document' => $document
        ], 201);
    }

    public function reuseDocument(Request $request, $id)
    {
        $data = $request->validate([
            'document_id' => 'required|exists:documents,id',
            'scholarship_requirement_id' => 'required|exists:scholarship_requirements,id',
        ]);

        $student = Student::where('user_id', $request->user()->id)->first();
        $application = Application::with('scholarship.requirements')->findOrFail($id);

        if (!$student || $application->student_id !== $student->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if (!in_array($application->status, ['draft', 'needs_action'])) {
            return response()->json(['message' => 'Documents cannot be changed at this stage.'], 422);
        }

        $requirement = $application->scholarship->requirements->firstWhere('id', (int) $data['scholarship_requirement_id']);

        if (!$requirement) {
            return response()->json(['message' => 'That requirement is not part of this scholarship.'], 422);
        }

        $source = Document::findOrFail($data['document_id']);

        if ($source->student_id !== $student->id) {
            return response()->json(['message' => 'You can only reuse your own documents.'], 403);
        }

        if ($source->isExpired()) {
            return response()->json([
                'message' => 'This saved document expired on '.$source->expiresAt()->format('F j, Y').'. Please upload a new copy.'
            ], 422);
        }

        $previous = Document::where('application_id', $application->id)
            ->where('scholarship_requirement_id', $requirement->id)
            ->get();

        foreach ($previous as $old) {
            $old->delete();
            if ($old->file_path !== $source->file_path) {
                Document::deleteFileIfUnused($old->file_path);
            }
        }

        $document = Document::create([
            'student_id' => $student->id,
            'application_id' => $application->id,
            'scholarship_requirement_id' => $requirement->id,
            'original_filename' => $source->original_filename,
            'file_path' => $source->file_path,
            'document_type' => $requirement->name,
            'status' => 'uploaded',
        ]);

        return response()->json([
            'message' => "Saved document used for {$requirement->name}.",
            'document' => $document,
        ], 201);
    }

    public function review(Request $request, $id)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Only staff can review applications.'
            ], 403);
        }

        $data = $request->validate([
            'status' => [
                'required',
                'in:under_review,needs_action,complete,approved,rejected'
            ],
            'remarks' => 'nullable|string|max:5000',
        ]);

        $application = Application::findOrFail($id);
        $previousStatus = $application->status;

        if ($error = $application->staffChangeError($data['status'])) {
            return response()->json(['message' => $error], 422);
        }

        if ($data['status'] === 'needs_action' && blank($data['remarks'] ?? null)) {
            return response()->json([
                'message' => 'Write in Remarks what the student needs to fix.'
            ], 422);
        }

        $changes = [
            'status' => $data['status'],
            'remarks' => $data['remarks'] ?? null,
        ];

        if ($data['status'] === 'complete' && !in_array($previousStatus, ['complete', 'approved', 'rejected'], true)) {
            $changes['forwarded_at'] = now();
        }

        if ($data['status'] !== 'approved') {
            $changes['enrollment_verified'] = false;
            $changes['enrollment_verified_at'] = null;
        }

        $application->update($changes);

        $emailLog = null;
        if ($data['status'] === 'approved' && $previousStatus !== 'approved') {
            $emailLog = ApplicationNotifier::approved($application, 'review');
        }

        if ($previousStatus !== $data['status']) {
            $application->loadMissing('student:id,first_name,last_name', 'scholarship:id,name');
            ActivityLog::record($request->user(), 'application.'.($data['status'] === 'complete' ? 'forwarded' : $data['status']),
                'Application of '.trim(($application->student?->first_name ?? '').' '.($application->student?->last_name ?? '')).' ('.$application->scholarship?->name.'): '
                .str_replace('_', ' ', $previousStatus).' → '.str_replace('_', ' ', $data['status']).'.', $application);
        }

        if ($previousStatus !== $data['status'] || !empty($data['remarks'])) {
            ApplicationStatusLog::record(
                $application,
                $data['status'],
                $data['remarks'] ?? null,
                $request->user()->id,
                $previousStatus
            );
        }

        return response()->json([
            'message' => 'Application review updated successfully.',
            'email' => $emailLog ? ['status' => $emailLog->status, 'to' => $emailLog->to_email, 'mode' => \App\Support\MailMode::mode()] : null,
            'application' => $application->fresh()->load([
                'student',
                'scholarship',
                'documents.validationResult'
            ])
        ]);
    }
}