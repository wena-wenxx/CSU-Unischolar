<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ApplicationStatusLog;
use App\Models\Document;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ApplicationController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | STUDENT
    |--------------------------------------------------------------------------
    */

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

        // Closed, inactive, not yet open, or past its deadline.
        if (!$scholarship->is_open) {
            return response()->json([
                'message' => $scholarship->closedReason()
            ], 422);
        }

        $activeRecord = ScholarRecord::with('scholarship:id,name')
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->first();

        if ($activeRecord) {
            return response()->json([
                'message' => 'You are currently a grantee of '.$activeRecord->scholarship->name
                    .'. Only one active scholarship is allowed, so you cannot apply for a new one at this time.'
            ], 422);
        }

        $alreadyApplied = Application::where(
            'student_id',
            $student->id
        )
            ->where('scholarship_id', $scholarship->id)
            ->exists();

        if ($alreadyApplied) {
            return response()->json([
                'message' => 'You already applied to this scholarship.'
            ], 422);
        }

        $application = Application::create([
            'student_id' => $student->id,
            'scholarship_id' => $scholarship->id,
            'status' => 'draft',
        ]);

        ApplicationStatusLog::record($application, 'draft', null, $request->user()->id);

        return response()->json(
            $application->load('scholarship'),
            201
        );
    }


    /*
    |--------------------------------------------------------------------------
    | STUDENT - SUBMIT APPLICATION
    |--------------------------------------------------------------------------
    */

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
                'message' => 'This application cannot be submitted in its current status.'
            ], 422);
        }

        // A first submission must be made before the deadline. A resubmission
        // that OAS asked for (needs_action) is still accepted afterwards.
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

        return response()->json([
            'message' => 'Application submitted successfully.',
            'application' => $application->fresh()->load([
                'documents',
                'scholarship'
            ])
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | STUDENT - MY APPLICATIONS
    |--------------------------------------------------------------------------
    */

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


    /*
    |--------------------------------------------------------------------------
    | STAFF - ALL APPLICATIONS
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        // The list only needs what the tables, filters and CSV reports show.
        // Full documents and AI results are loaded per application in show().
        // Newest activity first: whatever changed most recently is on top.
        $applications = Application::with([
            'student',
            'scholarship:id,name,provider,category,amount,status,application_start,application_end',
            'scholarship.requirements:id,scholarship_id,name,is_required',
            'documents:id,application_id,scholarship_requirement_id,status',
            'latestLog',
        ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        return response()->json($applications);
    }


    /*
    |--------------------------------------------------------------------------
    | STAFF / STUDENT (OWNER) - VIEW ONE APPLICATION
    |--------------------------------------------------------------------------
    | Staff can open any application. A student can open only their own.
    */

    public function show(Request $request, $id)
    {
        $application = Application::with([
            'student',
            'scholarship.requirements',
            'documents.validationResult',
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
        }

        return response()->json($application);
    }


    /*
    |--------------------------------------------------------------------------
    | STUDENT / STAFF - UPLOAD DOCUMENT
    |--------------------------------------------------------------------------
    */

    public function uploadDocument(Request $request, $id)
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png',
            'scholarship_requirement_id' => 'nullable|exists:scholarship_requirements,id',
            'document_type' => 'nullable|string|max:255',
        ]);

        $application = Application::findOrFail($id);

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
                    'message' => 'Documents cannot be uploaded at this stage.'
                ], 422);
            }
        }

        $file = $request->file('file');

        // Uploading again for the same requirement replaces the old file,
        // so each requirement has one current document.
        if ($request->scholarship_requirement_id) {
            $previous = Document::where('application_id', $application->id)
                ->where('scholarship_requirement_id', $request->scholarship_requirement_id)
                ->get();

            foreach ($previous as $old) {
                Storage::disk('public')->delete($old->file_path);
                $old->delete(); // its AI result is removed with it
            }
        }

        $path = $file->store(
            'documents',
            'public'
        );

        $document = Document::create([
            'application_id' => $application->id,
            'scholarship_requirement_id' =>
                $request->scholarship_requirement_id,
            'original_filename' =>
                $file->getClientOriginalName(),
            'file_path' => $path,
            'document_type' =>
                $request->document_type,
            'status' => 'uploaded',
        ]);

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'document' => $document
        ], 201);
    }


    /*
    |--------------------------------------------------------------------------
    | STAFF - APPLICATION REVIEW
    |--------------------------------------------------------------------------
    */

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

        $changes = [
            'status' => $data['status'],
            'remarks' => $data['remarks'] ?? null,
        ];

        // Enrollment verification only makes sense for approved
        // applications. If staff move it away from "approved", clear it.
        if ($data['status'] !== 'approved') {
            $changes['enrollment_verified'] = false;
            $changes['enrollment_verified_at'] = null;
        }

        $application->update($changes);

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
            'application' => $application->fresh()->load([
                'student',
                'scholarship',
                'documents.validationResult'
            ])
        ]);
    }
}