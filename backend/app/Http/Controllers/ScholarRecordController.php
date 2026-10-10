<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
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
        if ($request->user()->role !== 'staff' && !$request->user()->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $records = ScholarRecord::with([
            'student:id,student_id,first_name,middle_name,last_name,sex,course,year_level,college',
            'scholarship:id,name,short_name,provider,category,amount,application_mode',
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
            'atm_funds' => 'nullable|in:'.implode(',', ScholarRecord::ATM_FUNDS),
            'atm_note' => 'nullable|string|max:100',
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
                'atm_funds' => !empty($data['has_atm']) ? ($data['atm_funds'] ?? 'pending') : null,
                'atm_note' => !empty($data['has_atm']) ? null : ($data['atm_note'] ?? 'For ATM application'),
                'grantee_tagged_at' => now(),
                'remarks' =>
                    $data['remarks'] ?? null,
            ]
        );

        ApplicationStatusLog::record($application, 'grantee_tagged', null, $request->user()->id, 'approved');
        ActivityLog::record($request->user(), 'grantee.tagged', 'Tagged '.trim(($application->student?->first_name ?? '').' '.($application->student?->last_name ?? '')).' as grantee of '.$application->scholarship?->name.'.', $record);

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
            'atm_funds' =>
                'nullable|in:'.implode(',', ScholarRecord::ATM_FUNDS),
            'atm_note' =>
                'nullable|string|max:100',
            'remarks' =>
                'nullable|string|max:5000',
        ]);

        $record = ScholarRecord::findOrFail($id);

        // Funds only apply to a grantee with an ATM; the note only to one without.
        if (array_key_exists('has_atm', $data)) {
            if ($data['has_atm']) {
                $data['atm_note'] = null;
                $data['atm_funds'] = $data['atm_funds'] ?? ($record->atm_funds ?: 'pending');
            } else {
                $data['atm_funds'] = null;
                $data['atm_note'] = $data['atm_note'] ?? ($record->atm_note ?: 'For ATM application');
            }
        }

        $record->fill($data);
        $changed = array_keys($record->getDirty());
        $record->save();

        if ($changed) {
            $record->loadMissing('student:id,first_name,last_name', 'scholarship:id,name');
            ActivityLog::record($request->user(), 'grantee.updated',
                'Updated scholar record of '.trim($record->student?->first_name.' '.$record->student?->last_name)
                .' ('.$record->scholarship?->name.'): '.implode(', ', $changed).'.', $record);
        }

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
