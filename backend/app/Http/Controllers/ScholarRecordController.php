<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ScholarRecord;
use App\Models\Student;
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
    | STAFF - CREATE SCHOLAR RECORD
    |--------------------------------------------------------------------------
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
            'currently_enrolled' => 'required|boolean',
            'has_atm' => 'required|boolean',
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
                    $data['currently_enrolled'],
                'has_atm' =>
                    $data['has_atm'],
                'grantee_tagged_at' => now(),
                'remarks' =>
                    $data['remarks'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Scholar record created successfully.',
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


    /*
    |--------------------------------------------------------------------------
    | STUDENT - MY SCHOLARSHIP HISTORY
    |--------------------------------------------------------------------------
    */

    public function myRecords(Request $request)
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

        return response()->json(
            ScholarRecord::with('scholarship')
                ->where('student_id', $student->id)
                ->latest()
                ->get()
        );
    }
}