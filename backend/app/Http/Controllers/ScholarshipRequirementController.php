<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ScholarRecord;
use Illuminate\Http\Request;

class ScholarRecordController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $records = ScholarRecord::with([
            'student',
            'scholarship',
            'payrollRecords'
        ])->latest()->get();

        return response()->json($records);
    }

    public function createFromApplication(
        Request $request,
        $applicationId
    ) {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $application = Application::with([
            'student',
            'scholarship'
        ])->findOrFail($applicationId);

        if ($application->status !== 'approved') {
            return response()->json([
                'message' => 'Only approved applications can become scholar records.'
            ], 422);
        }

        $existingActive = ScholarRecord::where(
            'student_id',
            $application->student_id
        )
        ->where('status', 'active')
        ->exists();

        if ($existingActive) {
            return response()->json([
                'message' => 'Student already has an active scholarship.'
            ], 422);
        }

        $record = ScholarRecord::create([
            'student_id' => $application->student_id,
            'scholarship_id' => $application->scholarship_id,
            'status' => 'active',
            'currently_enrolled' => false,
            'has_atm' => false,
            'grantee_tagged_at' => now(),
        ]);

        return response()->json([
            'message' => 'Scholar record created successfully.',
            'record' => $record->load([
                'student',
                'scholarship'
            ])
        ], 201);
    }

    public function verifyEnrollment(
        Request $request,
        $id
    ) {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            'currently_enrolled' => 'required|boolean',
            'remarks' => 'nullable|string',
        ]);

        $record = ScholarRecord::findOrFail($id);

        $record->update([
            'currently_enrolled' =>
                $validated['currently_enrolled'],
            'remarks' =>
                $validated['remarks'] ?? $record->remarks,
        ]);

        return response()->json([
            'message' => 'Enrollment status updated.',
            'record' => $record->fresh()
        ]);
    }

    public function updateAtmStatus(
        Request $request,
        $id
    ) {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            'has_atm' => 'required|boolean',
        ]);

        $record = ScholarRecord::findOrFail($id);

        $record->update([
            'has_atm' => $validated['has_atm'],
        ]);

        return response()->json([
            'message' => 'ATM status updated.',
            'record' => $record->fresh()
        ]);
    }
}