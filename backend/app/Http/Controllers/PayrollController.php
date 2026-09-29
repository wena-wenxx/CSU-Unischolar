<?php

namespace App\Http\Controllers;

use App\Models\PayrollRecord;
use App\Models\ScholarRecord;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $records = PayrollRecord::with([
            'scholarRecord.student',
            'scholarRecord.scholarship'
        ])->latest()->get();

        return response()->json($records);
    }

    public function store(Request $request)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            'scholar_record_id' => 'required|exists:scholar_records,id',
            'amount' => 'required|numeric|min:0',
            'period' => 'required|string|max:255',
            'bank_atm_status' => 'required|string|max:255',
        ]);

        $scholar = ScholarRecord::findOrFail(
            $validated['scholar_record_id']
        );

        if ($scholar->status !== 'active') {
            return response()->json([
                'message' => 'Only active scholar records can be added to payroll.'
            ], 422);
        }

        $payroll = PayrollRecord::create([
            'scholar_record_id' =>
                $validated['scholar_record_id'],
            'amount' => $validated['amount'],
            'period' => $validated['period'],
            'bank_atm_status' =>
                $validated['bank_atm_status'],
            'status' => 'draft',
        ]);

        return response()->json([
            'message' => 'Payroll record created.',
            'payroll' => $payroll
        ], 201);
    }
}