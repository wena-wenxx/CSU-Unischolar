<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ProfileChangeRequest;
use App\Models\Student;
use Illuminate\Http\Request;

/*
| Staff side of "Request a correction". OAS checks the request with the
| Registrar's records, then marks it resolved with a short remark.
*/
class ProfileChangeRequestController extends Controller
{
    private function staffOnly(Request $request): void
    {
        if ($request->user()->role !== 'staff') {
            abort(response()->json(['message' => 'Unauthorized.'], 403));
        }
    }

    // GET /staff/profile-requests?status=pending
    public function index(Request $request)
    {
        $this->staffOnly($request);

        $query = ProfileChangeRequest::with('student')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->get());
    }

    // PATCH /staff/profile-requests/{id}
    public function resolve(Request $request, $id)
    {
        $this->staffOnly($request);

        $data = $request->validate([
            'staff_remarks' => 'nullable|string|max:1000',
            'apply_change' => 'sometimes|boolean',
        ]);

        $changeRequest = ProfileChangeRequest::with('student')->findOrFail($id);

        if ($changeRequest->status === 'resolved') {
            return response()->json(['message' => 'This request is already resolved.'], 422);
        }

        // Optional: after checking with the Registrar, copy the corrected
        // value into the student's record.
        if (!empty($data['apply_change'])) {
            $field = $changeRequest->field;
            $value = trim($changeRequest->requested_value);

            if ($field === 'student_id'
                && Student::where('student_id', $value)->where('id', '!=', $changeRequest->student_id)->exists()) {
                return response()->json([
                    'message' => "Another student already has the Student ID {$value}. The change was not applied."
                ], 422);
            }

            $changeRequest->student->update([$field => $value]);
        }

        $changeRequest->update([
            'status' => 'resolved',
            'staff_remarks' => $data['staff_remarks'] ?? null,
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        $name = trim($changeRequest->student->first_name.' '.$changeRequest->student->last_name);
        ActivityLog::record($request->user(), 'profile_request.resolved',
            "Resolved {$name}'s correction request ({$changeRequest->field})".(!empty($data['apply_change']) ? ', change applied.' : '.'), $changeRequest);

        return response()->json([
            'message' => !empty($data['apply_change'])
                ? 'Change applied to the student record and request resolved.'
                : 'Request marked as resolved.',
            'request' => $changeRequest->load('student'),
        ]);
    }
}
