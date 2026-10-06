<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ApplicationStatusLog;
use App\Models\ScholarRecord;
use Illuminate\Http\Request;

class ScholarRecordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | STAFF - LIST SCHOLARS
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $records = ScholarRecord::with([
            'student',
            'scholarship'
        ])
            ->latest()
            ->get();

        return response()->json($records);
    }


    /*
    |--------------------------------------------------------------------------
    | STAFF - VIEW ONE SCHOLAR RECORD
    |--------------------------------------------------------------------------
    */

    public function show(Request $request, $id)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $record = ScholarRecord::with([
            'student',
            'scholarship',
            'payrollRecords'
        ])->findOrFail($id);

        return response()->json($record);
    }


    /*
    |--------------------------------------------------------------------------
    | STAFF - TAG GRANTEE (CREATE SCHOLAR RECORD)
    |--------------------------------------------------------------------------
    | Allowed only after the agency approved the application AND OAS
    | verified enrollment (POST /applications/{id}/verify-enrollment).
    | Body: has_atm (boolean), remarks (optional).
    | currently_enrolled is optional; it defaults to the verification result.
    */

    public function store(Request $request, $applicationId = null)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        // The route is /applications/{applicationId}/scholar-record, so the
        // application ID normally comes from the URL. A body value is still
        // accepted for older clients.
        if ($applicationId !== null) {
            $request->merge(['application_id' => $applicationId]);
        }

        $data = $request->validate([
            'application_id' => 'required|exists:applications,id',
            'currently_enrolled' => 'sometimes|boolean',
            'has_atm' => 'sometimes|boolean',
            'remarks' => 'nullable|string|max:5000',
        ]);

        $application = Application::with([
            'student',
            'scholarship'
        ])->findOrFail(
            $data['application_id']
        );

        if ($application->status !== 'approved') {
            return response()->json([
                'message' => 'Only approved applications can become scholar records.'
            ], 422);
        }

        if (!$application->enrollment_verified) {
            return response()->json([
                'message' => 'Verify this student\'s enrollment before tagging them as a grantee.'
            ], 422);
        }

        // Hard rule: one active scholarship per student.
        $alreadyActive = ScholarRecord::where(
            'student_id',
            $application->student_id
        )
            ->where('status', 'active')
            ->where(
                'scholarship_id',
                '!=',
                $application->scholarship_id
            )
            ->exists();

        if ($alreadyActive) {
            return response()->json([
                'message' => 'Student already has another active scholarship.'
            ], 422);
        }

        $record = ScholarRecord::updateOrCreate(
            [
                'student_id' =>
                    $application->student_id,
                'scholarship_id' =>
                    $application->scholarship_id,
            ],
            [
                'status' => 'active',
                'currently_enrolled' =>
                    $data['currently_enrolled'] ?? true,
                'has_atm' =>
                    $data['has_atm'] ?? false,
                'grantee_tagged_at' => now(),
                'remarks' =>
                    $data['remarks'] ?? null,
            ]
        );

        ApplicationStatusLog::record($application, 'grantee_tagged', null, $request->user()->id, 'approved');

        return response()->json([
            'message' => 'Student tagged as grantee.',
            'scholar_record' => $record->load([
                'student',
                'scholarship'
            ])
        ], 201);
    }


    /*
    |--------------------------------------------------------------------------
    | STAFF - UPDATE SCHOLAR
    |--------------------------------------------------------------------------
    */

    public function update(Request $request, $id)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $data = $request->validate([
            'status' =>
                'sometimes|in:active,inactive,completed',
            'currently_enrolled' =>
                'sometimes|boolean',
            'has_atm' =>
                'sometimes|boolean',
            'remarks' =>
                'nullable|string|max:5000',
        ]);

        $record = ScholarRecord::findOrFail($id);

        $record->update($data);

        return response()->json([
            'message' => 'Scholar record updated successfully.',
            'scholar_record' =>
                $record->fresh()->load([
                    'student',
                    'scholarship'
                ])
        ]);
    }
}
