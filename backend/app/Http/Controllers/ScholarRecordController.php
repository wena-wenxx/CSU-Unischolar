<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ScholarRecord;
use App\Models\Student;
use Illuminate\Http\Request;

/**
 * Order matches the real OAS workflow:
 *   Application approved (by the external agency)
 *        -> Scholar record created (currently_enrolled/has_atm NOT yet confirmed)
 *        -> Staff verifies enrollment
 *        -> Staff verifies/records ATM status
 *   Only an ENROLLED, active record is eligible for payroll.
 */
class ScholarRecordController extends Controller
{
    private function staffOnly(Request $request)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return null;
    }

    public function index(Request $request)
    {
        if ($denied = $this->staffOnly($request)) {
            return $denied;
        }

        $records = ScholarRecord::with(['student', 'scholarship', 'payrollRecords'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('id', 'desc')->get();

        return response()->json($records);
    }

    // Create the scholar record from an APPROVED application
    public function createFromApplication(Request $request, $applicationId)
    {
        if ($denied = $this->staffOnly($request)) {
            return $denied;
        }

        $application = Application::with('student', 'scholarship')->findOrFail($applicationId);

        if ($application->status !== 'approved') {
            return response()->json(['message' => 'Only approved applications can become scholar records.'], 422);
        }

        $existingActive = ScholarRecord::where('student_id', $application->student_id)->where('status', 'active')->exists();
        if ($existingActive) {
            return response()->json(['message' => 'This student already has an active scholarship.'], 422);
        }

        $record = ScholarRecord::create([
            'student_id' => $application->student_id,
            'scholarship_id' => $application->scholarship_id,
            'status' => 'active',
            'currently_enrolled' => false, // confirmed in a separate step below
            'has_atm' => false,
            'grantee_tagged_at' => now(),
        ]);

        return response()->json([
            'message' => 'Scholar record created. Next: verify enrollment.',
            'record' => $record->load('student', 'scholarship'),
        ], 201);
    }

    // Step: verify enrollment
    public function verifyEnrollment(Request $request, $id)
    {
        if ($denied = $this->staffOnly($request)) {
            return $denied;
        }

        $data = $request->validate(['currently_enrolled' => 'required|boolean', 'remarks' => 'nullable|string']);
        $record = ScholarRecord::findOrFail($id);
        $record->update([
            'currently_enrolled' => $data['currently_enrolled'],
            'remarks' => $data['remarks'] ?? $record->remarks,
        ]);

        return response()->json(['message' => 'Enrollment status updated.', 'record' => $record->fresh()]);
    }

    // Step: record ATM status (needed before payroll can mark this student "ready")
    public function updateAtmStatus(Request $request, $id)
    {
        if ($denied = $this->staffOnly($request)) {
            return $denied;
        }

        $data = $request->validate(['has_atm' => 'required|boolean']);
        $record = ScholarRecord::findOrFail($id);
        $record->update(['has_atm' => $data['has_atm']]);

        return response()->json(['message' => 'ATM status updated.', 'record' => $record->fresh()]);
    }

    // General update (status changes, remarks)
    public function update(Request $request, $id)
    {
        if ($denied = $this->staffOnly($request)) {
            return $denied;
        }

        $data = $request->validate(['status' => 'sometimes|in:active,inactive,completed', 'remarks' => 'nullable|string']);
        $record = ScholarRecord::findOrFail($id);

        if (($data['status'] ?? null) === 'active') {
            $other = ScholarRecord::where('student_id', $record->student_id)->where('status', 'active')->where('id', '!=', $record->id)->exists();
            if ($other) {
                return response()->json(['message' => 'This student already has another active scholarship.'], 422);
            }
        }

        $record->update($data);

        return response()->json($record->load('student', 'scholarship'));
    }

    public function mine(Request $request)
    {
        $student = Student::where('user_id', $request->user()->id)->first();
        if (! $student) {
            return response()->json(['message' => 'Student profile not found'], 404);
        }

        return response()->json(ScholarRecord::with('scholarship')->where('student_id', $student->id)->orderBy('id', 'desc')->get());
    }
}
