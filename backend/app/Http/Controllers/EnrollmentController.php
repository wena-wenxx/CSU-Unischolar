<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ApplicationStatusLog;
use App\Models\ScholarRecord;
use App\Models\User;
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

        self::record($application, $enrolled, $data['remarks'] ?? null, $request->user());

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

    /*
    | Saves one enrollment check (also used by "Verify All Enrollments"):
    | the application, its step history, the activity log, and the scholar
    | record's enrollment flag (payroll checks it).
    */
    public static function record(Application $application, bool $enrolled, ?string $remarks, User $by): void
    {
        $application->update([
            'enrollment_verified' => $enrolled,
            'enrollment_verified_at' => $enrolled ? now() : null,
            'remarks' => $remarks ?? $application->remarks,
        ]);

        ApplicationStatusLog::record(
            $application,
            $enrolled ? 'enrollment_verified' : 'enrollment_not_verified',
            $remarks,
            $by->id,
            'approved'
        );

        $application->loadMissing('student:id,first_name,last_name', 'scholarship:id,name');
        ActivityLog::record($by, $enrolled ? 'enrollment.verified' : 'enrollment.not_enrolled',
            ($enrolled ? 'Verified enrollment of ' : 'Recorded NOT enrolled: ')
            .trim(($application->student?->first_name ?? '').' '.($application->student?->last_name ?? ''))
            .' ('.$application->scholarship?->name.').', $application);

        ScholarRecord::where('student_id', $application->student_id)
            ->where('scholarship_id', $application->scholarship_id)
            ->update(['currently_enrolled' => $enrolled]);
    }
}
