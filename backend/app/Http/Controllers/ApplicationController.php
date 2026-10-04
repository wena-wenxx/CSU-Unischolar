<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Document;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        if ($scholarship->status !== 'active') {
            return response()->json([
                'message' => 'This scholarship is not open for applications.'
            ], 422);
        }

        $hasActiveScholarship = ScholarRecord::where(
            'student_id',
            $student->id
        )
            ->where('status', 'active')
            ->exists();

        if ($hasActiveScholarship) {
            return response()->json([
                'message' => 'You already have an active scholarship. Only one active scholarship is allowed.'
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

        $application->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

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
            ->latest()
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

        $applications = Application::with([
            'student',
            'scholarship.requirements',
            'documents.validationResult',
            'documents.requirement'
        ])
            ->latest('submitted_at')
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
            'documents.requirement'
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

        $application->update([
            'status' => $data['status'],
            'remarks' => $data['remarks'] ?? null,
        ]);

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