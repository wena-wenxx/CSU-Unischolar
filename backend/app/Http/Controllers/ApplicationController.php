<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Document;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\Student;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    // Student: submit an application
    public function store(Request $request)
    {
        if ($request->user()->role !== 'student') {
            return response()->json(['message' => 'Only students can apply'], 403);
        }

        $request->validate([
            'scholarship_id' => 'required|exists:scholarships,id',
        ]);

        $student = Student::where('user_id', $request->user()->id)->first();

        if (!$student) {
            return response()->json(['message' => 'Student profile not found'], 404);
        }

        $scholarship = Scholarship::findOrFail($request->scholarship_id);

        if ($scholarship->status !== 'active') {
            return response()->json([
                'message' => 'This scholarship is not open for applications.',
            ], 422);
        }

        // BUSINESS RULE: a student can only have ONE active scholarship at a time
        $hasActive = ScholarRecord::where('student_id', $student->id)
            ->where('status', 'active')
            ->exists();

        if ($hasActive) {
            return response()->json([
                'message' => 'You already have an active scholarship. Only one is allowed at a time.',
            ], 422);
        }

        // A student can only apply once to the same scholarship
        $already = Application::where('student_id', $student->id)
            ->where('scholarship_id', $scholarship->id)
            ->exists();

        if ($already) {
            return response()->json(['message' => 'You already applied to this scholarship.'], 422);
        }

        $application = Application::create([
            'student_id' => $student->id,
            'scholarship_id' => $scholarship->id,
            'status' => 'draft',
            'submitted_at' => null,
        ]);

        return response()->json($application, 201);
    }

    // Student: see my own applications
    public function myApplications(Request $request)
    {
        $student = Student::where('user_id', $request->user()->id)->first();

        if (!$student) {
            return response()->json(['message' => 'Student profile not found'], 404);
        }

        $apps = Application::with(['scholarship', 'documents'])
            ->where('student_id', $student->id)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json($apps);
    }

    // Staff: see all applications
    public function index(Request $request)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $apps = Application::with(['student', 'scholarship', 'documents.validationResult'])
            ->orderBy('submitted_at', 'desc')
            ->get();

        return response()->json($apps);
    }

    // Upload a document to an application
    public function uploadDocument(Request $request, $id)
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png',
            'scholarship_requirement_id' => 'nullable|exists:scholarship_requirements,id',
            'document_type' => 'nullable|string|max:255',
        ]);

        $application = Application::findOrFail($id);

        if ($request->scholarship_requirement_id) {
            $requirementBelongsToScholarship = $application->scholarship
                ->requirements()
                ->where('id', $request->scholarship_requirement_id)
                ->exists();

            if (!$requirementBelongsToScholarship) {
                return response()->json([
                    'message' => 'This requirement does not belong to the application scholarship.',
                ], 422);
            }
        }

        if (in_array($application->status, ['approved', 'rejected'])) {
            return response()->json([
                'message' => 'Documents cannot be uploaded after the application has been finalized.',
            ], 422);
        }

        // Students can only upload to their OWN application
        if ($request->user()->role === 'student') {
            $student = Student::where('user_id', $request->user()->id)->first();

            if (!$student || $student->id !== $application->student_id) {
                return response()->json(['message' => 'Unauthorized'], 403);
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

        return response()->json($document, 201);
    }

    public function submit(Request $request, $id)
    {
        $request->validate([
            'id' => 'required|exists:applications,id',
        ]);

        $application = Application::findOrFail($id);

        // Students can only submit their OWN application
        if ($request->user()->role === 'student') {
            $student = Student::where('user_id', $request->user()->id)->first();

            if (!$student || $student->id !== $application->student_id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        if ($application->status !== 'draft') {
            return response()->json(['message' => 'Only draft applications can be submitted.'], 422);
        }

        // Check if all required documents are uploaded
        $requiredRequirements = Scholarship::findOrFail($application->scholarship_id)
            ->requirements()
            ->where('is_required', true)
            ->get();

        foreach ($requiredRequirements as $requirement) {
            $documentExists = $application->documents()
                ->where('scholarship_requirement_id', $requirement->id)
                ->exists();

            if (!$documentExists) {
                return response()->json([
                    'message' => 'Required documents are incomplete.',
                    'missing_requirement' => $requirement->name,
                ], 422);
            }
        }

        $application->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        return response()->json([
            'message' => 'Application submitted successfully.',
            'application' => $application->fresh(),
        ]);
    }
    public function review(Request $request, $id)
{
    if ($request->user()->role !== 'staff') {
        return response()->json([
            'message' => 'Unauthorized'
        ], 403);
    }

    $validated = $request->validate([
        'status' => 'required|in:under_review,needs_action,complete,approved,rejected',
        'remarks' => 'nullable|string',
    ]);

    $application = Application::findOrFail($id);

    $application->update([
        'status' => $validated['status'],
        'remarks' => $validated['remarks'] ?? null,
    ]);

    return response()->json([
        'message' => 'Application review updated successfully.',
        'application' => $application->fresh([
            'student',
            'scholarship',
            'documents.validationResult'
        ])
    ]);
}
}
