<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ScholarRecord;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function verify(Request $request, $applicationId)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Only staff can verify enrollment.'
            ], 403);
        }

        $data = $request->validate([
            'currently_enrolled' => 'required|boolean',
            'remarks' => 'nullable|string|max:5000',
        ]);

        $application = Application::with([
            'student',
            'scholarship'
        ])->findOrFail($applicationId);

        if ($application->status !== 'approved') {
            return response()->json([
                'message' => 'Only approved applications can undergo enrollment verification.'
            ], 422);
        }

        $scholar = ScholarRecord::firstOrCreate(
            [
                'student_id' => $application->student_id,
                'scholarship_id' => $application->scholarship_id,
            ],
            [
                'status' => 'active',
                'currently_enrolled' =>
                    $data['currently_enrolled'],
                'has_atm' => false,
                'grantee_tagged_at' => now(),
                'remarks' => $data['remarks'] ?? null,
            ]
        );

        $scholar->update([
            'currently_enrolled' =>
                $data['currently_enrolled'],
            'remarks' =>
                $data['remarks'] ?? $scholar->remarks,
        ]);

        return response()->json([
            'message' => 'Enrollment verification recorded successfully.',
            'currently_enrolled' =>
                $scholar->currently_enrolled,
            'scholar_record' =>
                $scholar->load([
                    'student',
                    'scholarship'
                ])
        ]);
    }
}