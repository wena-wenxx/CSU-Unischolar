<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ScholarRecord;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | STAFF - VERIFY ENROLLMENT OF AN APPROVED APPLICANT
    |--------------------------------------------------------------------------
    | OAS workflow: the agency approves -> OAS verifies enrollment ->
    | OAS tags the grantee. This step ONLY records the verification on the
    | application. Tagging the grantee is a separate step
    | (POST /applications/{id}/scholar-record).
    |
    | Body: currently_enrolled (boolean, required), remarks (optional)
    */

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

        $enrolled = (bool) $data['currently_enrolled'];

        $application->update([
            'enrollment_verified' => $enrolled,
            'enrollment_verified_at' => $enrolled ? now() : null,
            'remarks' => $data['remarks'] ?? $application->remarks,
        ]);

        // If this student was already tagged as a grantee for this
        // scholarship, keep the scholar record's enrollment flag in step,
        // because payroll checks it.
        ScholarRecord::where('student_id', $application->student_id)
            ->where('scholarship_id', $application->scholarship_id)
            ->update(['currently_enrolled' => $enrolled]);

        return response()->json([
            'message' => $enrolled
                ? 'Enrollment verified. You can now tag this student as a grantee.'
                : 'Recorded: student is NOT currently enrolled.',
            'enrollment_verified' => $application->enrollment_verified,
            'enrollment_verified_at' => $application->enrollment_verified_at,
            'application' => $application->fresh()->load([
                'student',
                'scholarship'
            ]),
        ]);
    }
}
