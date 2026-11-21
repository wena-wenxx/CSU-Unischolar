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
                'message' => 'Only approved applications can become scholar records. First record the agency\'s approval: Applications → open the student → Approved by agency (or upload the agency\'s list in Approved Lists).'
            ], 422);
        }

        if (!$application->enrollment_verified) {
            return response()->json([
                'message' => 'Verify this student\'s enrollment before tagging them as a grantee: Applications → open the student → Verify enrollment (or Enrollment → Verify All Enrollments).'
            ], 422);
        }

        // Hard rule: one active scholarship per student (including scholarships
        // from outside parties recorded in External Grantees).
        $holding = $application->student?->otherActiveScholarship($application->scholarship_id);

        if ($holding) {
            return response()->json([
                'message' => "Student already has another active scholarship: {$holding}."
            ], 422);
        }

        // One scholar record per program per school year (older years stay as history).
        $record = ScholarRecord::updateOrCreate(
            [
                'student_id' =>
                    $application->student_id,
                'scholarship_id' =>
                    $application->scholarship_id,
                'school_year' =>
                    $application->school_year,
            ],
            [
                'semester' => $application->semester,
                'processing_batch_id' => $application->processing_batch_id,
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
            ] + array_filter([
                // carried over from the agency's approved list, when there was one
                // (a value already on the record is kept when the application has none)
                'control_number' => $application->agency_control_number,
                'award_number' => $application->agency_award_number,
                'award_amount' => $application->agency_amount,
                'award_batch' => $application->agency_batch,
            ], fn ($value) => $value !== null && $value !== '')
        );

        // A grant from an earlier school year of the same program is now finished (kept as history).
        $finished = ScholarRecord::where('student_id', $application->student_id)
            ->where('scholarship_id', $application->scholarship_id)
            ->where('id', '!=', $record->id)
            ->where('status', 'active')
            ->where('school_year', '<', $application->school_year)
            ->update(['status' => 'completed']);

        ApplicationStatusLog::record($application, 'grantee_tagged', $finished ? 'Earlier school year record marked completed.' : null, $request->user()->id, 'approved');
        ActivityLog::record($request->user(), 'grantee.tagged', 'Tagged '.trim(($application->student?->first_name ?? '').' '.($application->student?->last_name ?? '')).' as grantee of '.$application->scholarship?->name.'.', $record);

        return response()->json([
            'message' => 'Student tagged as grantee. Next: Payroll → Prepare payroll when the stipend period starts.',
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

        // Re-activating a grant follows the same one-active-scholarship rule as tagging.
        if (($data['status'] ?? null) === 'active' && $record->status !== 'active'
            && ($holding = $record->student?->otherActiveScholarship($record->scholarship_id))) {
            return response()->json(['message' => "Student already has another active scholarship: {$holding}."], 422);
        }

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
