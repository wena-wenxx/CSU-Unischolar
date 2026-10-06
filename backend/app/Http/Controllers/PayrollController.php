<?php

namespace App\Http\Controllers;

use App\Models\PayrollRecord;
use App\Models\ScholarRecord;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | STAFF - LIST PAYROLL
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $records = PayrollRecord::with([
            'scholarRecord.student',
            'scholarRecord.scholarship'
        ])
            ->latest()
            ->get();

        return response()->json($records);
    }


    /*
    |--------------------------------------------------------------------------
    | STAFF - CREATE PAYROLL RECORD
    |--------------------------------------------------------------------------
    */

    public function store(Request $request, $scholarRecordId = null)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        // The route is /scholar-records/{scholarRecordId}/payroll, so the
        // scholar record ID normally comes from the URL. A body value is
        // still accepted for older clients.
        if ($scholarRecordId !== null) {
            $request->merge(['scholar_record_id' => $scholarRecordId]);
        }

        $data = $request->validate([
            'scholar_record_id' =>
                'required|exists:scholar_records,id',
            'amount' =>
                'required|numeric|min:0',
            'period' =>
                'required|string|max:255',
            'bank_atm_status' =>
                'nullable|string|max:50',
            'status' =>
                'nullable|in:draft,ready,processed',
            'signature' =>
                'nullable|string|max:255',
        ]);

        $scholar = ScholarRecord::findOrFail(
            $data['scholar_record_id']
        );

        if ($scholar->status !== 'active') {
            return response()->json([
                'message' => 'Only active scholar records can be added to payroll.'
            ], 422);
        }

        if (!$scholar->currently_enrolled) {
            return response()->json([
                'message' => 'Student must be currently enrolled before payroll preparation.'
            ], 422);
        }

        $record = PayrollRecord::create([
            'scholar_record_id' =>
                $data['scholar_record_id'],
            'amount' =>
                $data['amount'],
            'period' =>
                $data['period'],
            // If staff did not type an ATM status, use the scholar record's
            // has_atm flag ("Yes" / "No", matching the column default "No").
            'bank_atm_status' =>
                $data['bank_atm_status']
                ?? ($scholar->has_atm ? 'Yes' : 'No'),
            'status' =>
                $data['status'] ?? 'draft',
            'signature' =>
                $data['signature'] ?? null,
        ]);

        return response()->json([
            'message' => 'Payroll record created successfully.',
            'payroll' => $record->load([
                'scholarRecord.student',
                'scholarRecord.scholarship'
            ])
        ], 201);
    }


    /*
    |--------------------------------------------------------------------------
    | STAFF - UPDATE PAYROLL
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
            'amount' =>
                'sometimes|numeric|min:0',
            'period' =>
                'sometimes|string|max:255',
            'bank_atm_status' =>
                'sometimes|string|max:50',
            'status' =>
                'sometimes|in:draft,ready,processed',
            'signature' =>
                'nullable|string|max:255',
        ]);

        $payroll = PayrollRecord::findOrFail($id);

        $payroll->update($data);

        return response()->json([
            'message' => 'Payroll record updated successfully.',
            'payroll' =>
                $payroll->fresh()->load([
                    'scholarRecord.student',
                    'scholarRecord.scholarship'
                ])
        ]);
    }
}
