<?php

namespace App\Http\Controllers;

use App\Models\PayrollRecord;
use App\Models\ScholarRecord;
use Illuminate\Http\Request;

/** Payroll-READY preparation only. No banking, no disbursement. */
class PayrollController extends Controller
{
    private function staffOnly(Request $request)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return null;
    }

    private function filtered(Request $request)
    {
        return PayrollRecord::with(['scholarRecord.student', 'scholarRecord.scholarship'])
            ->when($request->query('period'), fn ($q, $p) => $q->where('period', $p))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('scholarship_id'), fn ($q, $sid) => $q->whereHas('scholarRecord', fn ($r) => $r->where('scholarship_id', $sid)))
            ->orderBy('id', 'desc');
    }

    public function index(Request $request)
    {
        if ($denied = $this->staffOnly($request)) {
            return $denied;
        }

        return response()->json($this->filtered($request)->get());
    }

    // Create ONE payroll record manually for a specific (already-verified) scholar record
    public function store(Request $request)
    {
        if ($denied = $this->staffOnly($request)) {
            return $denied;
        }

        $data = $request->validate([
            'scholar_record_id' => 'required|exists:scholar_records,id',
            'amount' => 'required|numeric|min:0',
            'period' => 'required|string|max:255',
        ]);

        $scholar = ScholarRecord::findOrFail($data['scholar_record_id']);
        if ($scholar->status !== 'active') {
            return response()->json(['message' => 'Only active scholar records can be added to payroll.'], 422);
        }
        if (! $scholar->currently_enrolled) {
            return response()->json(['message' => 'This student\'s enrollment has not been verified yet.'], 422);
        }

        $payroll = PayrollRecord::firstOrCreate(
            ['scholar_record_id' => $scholar->id, 'period' => $data['period']],
            ['amount' => $data['amount'], 'bank_atm_status' => $scholar->has_atm ? 'Yes' : 'No',
                'status' => $scholar->has_atm ? 'ready' : 'draft']
        );

        return response()->json(['message' => 'Payroll record created.', 'payroll' => $payroll->load('scholarRecord.student', 'scholarRecord.scholarship')], 201);
    }

    // Batch-generate for every ACTIVE + ENROLLED grantee of a scholarship (matches OAS's actual batch process)
    public function generate(Request $request)
    {
        if ($denied = $this->staffOnly($request)) {
            return $denied;
        }

        $data = $request->validate([
            'scholarship_id' => 'required|exists:scholarships,id',
            'period' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0',
        ]);

        $records = ScholarRecord::where('scholarship_id', $data['scholarship_id'])
            ->where('status', 'active')->where('currently_enrolled', true)->get();

        $created = 0;
        $skipped = 0;
        foreach ($records as $record) {
            $exists = PayrollRecord::where('scholar_record_id', $record->id)->where('period', $data['period'])->exists();
            if ($exists) {
                $skipped++;

                continue;
            }
            PayrollRecord::create([
                'scholar_record_id' => $record->id,
                'amount' => $data['amount'],
                'period' => $data['period'],
                'bank_atm_status' => $record->has_atm ? 'Yes' : 'No',
                'status' => $record->has_atm ? 'ready' : 'draft',
            ]);
            $created++;
        }

        return response()->json(['message' => "Payroll prepared: $created created, $skipped already existed.", 'created' => $created, 'skipped' => $skipped], 201);
    }

    public function update(Request $request, $id)
    {
        if ($denied = $this->staffOnly($request)) {
            return $denied;
        }

        $data = $request->validate([
            'status' => 'sometimes|in:draft,ready,processed',
            'amount' => 'sometimes|numeric|min:0',
            'signature' => 'nullable|string|max:255',
            'bank_atm_status' => 'sometimes|string|max:50',
        ]);

        $payroll = PayrollRecord::findOrFail($id);
        $payroll->update($data);

        return response()->json($payroll->load('scholarRecord.student', 'scholarRecord.scholarship'));
    }

    public function export(Request $request)
    {
        if ($denied = $this->staffOnly($request)) {
            return $denied;
        }

        $query = $this->filtered($request);
        if ($request->boolean('only_ready')) {
            $query->where('status', 'ready');
        }
        $rows = $query->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Student Name', 'Student ID', 'Scholarship', 'Amount', 'Bank/ATM Status', 'Period', 'Status', 'Signature']);
            foreach ($rows as $r) {
                $student = optional($r->scholarRecord)->student;
                fputcsv($out, [
                    trim(($student->first_name ?? '').' '.($student->last_name ?? '')),
                    $student->student_id ?? '',
                    optional(optional($r->scholarRecord)->scholarship)->name,
                    $r->amount, $r->bank_atm_status, $r->period, $r->status, $r->signature,
                ]);
            }
            fclose($out);
        }, 'payroll-ready.csv', ['Content-Type' => 'text/csv']);
    }
}
