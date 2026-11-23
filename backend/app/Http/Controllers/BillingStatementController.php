<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Support\DemoListFiles;
use Illuminate\Http\Request;

/*
| POST /staff/billing-statement
| Body: scholarship_id, school_year (e.g. 2026-2027), semester (optional), format xlsx|pdf
|
| Makes the billing list of one program and school year from the scholar records, in the column
| order of the CHED TES billing list (Form 2, Annex 2) that the Approved Lists page reads back.
| It is prepared by the OAS for checking before it is encoded or submitted; it is NOT the official
| CHED form and nothing is sent to CHED or UniFAST. Values the system does not keep (birthdate,
| PWD) stay blank, and a missing award number or amount is marked so staff fill it in.
*/
class BillingStatementController extends Controller
{
    public const COLUMNS = ['Control Number', 'Student Number', 'TES Award Number', 'Last Name', 'Given Name',
        'Middle Initial', 'Sex at Birth', 'Birthdate', 'Degree Program', 'Year Level', 'Email Address',
        'Phone Number', 'TES Batch', 'TES Amount', 'PWD', 'TOTAL AMOUNT'];

    public function export(Request $request)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json(['message' => 'Only OAS staff can make a billing statement.'], 403);
        }

        $data = $request->validate([
            'scholarship_id' => 'required|exists:scholarships,id',
            'school_year' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'semester' => 'nullable|string|max:40',
            'format' => 'required|in:xlsx,pdf',
        ]);

        $scholarship = Scholarship::findOrFail($data['scholarship_id']);

        // Grantees of this program and school year who are still grantees (active) or finished the
        // year (completed); inactive (dropped) grantees are not billed.
        $records = ScholarRecord::with(['student.user:id,email'])
            ->where('scholarship_id', $scholarship->id)
            ->where('school_year', $data['school_year'])
            ->whereIn('status', ['active', 'completed'])
            ->when($data['semester'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('semester', $s)->orWhereNull('semester')))
            ->get()
            ->sortBy(fn ($r) => mb_strtolower(($r->student?->last_name ?? '').' '.($r->student?->first_name ?? '')))
            ->values();

        if ($records->isEmpty()) {
            return response()->json(['message' => "No grantees of {$scholarship->name} for school year {$data['school_year']}"
                .(($data['semester'] ?? null) ? ", {$data['semester']}" : '').'. Tag the grantees in Scholar Records first.'], 422);
        }

        $missing = 0;
        $rows = $records->values()->map(function (ScholarRecord $r, int $i) use ($scholarship, &$missing) {
            $s = $r->student;
            $amount = $r->award_amount !== null ? (float) $r->award_amount : ($scholarship->amount !== null ? (float) $scholarship->amount : null);
            if (!$r->award_number || $amount === null) {
                $missing++;
            }
            $middle = trim((string) $s?->middle_name);

            return [
                $r->control_number ?: str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT),
                $s?->student_id,
                $r->award_number ?: 'TO FILL IN',
                mb_strtoupper((string) $s?->last_name),
                mb_strtoupper((string) $s?->first_name),
                $middle !== '' ? mb_strtoupper(mb_substr($middle, 0, 1)) : '',
                mb_strtoupper((string) $s?->sex),
                '',
                mb_strtoupper((string) $s?->course),
                preg_replace('/\D/', '', (string) $s?->year_level),
                $s?->user?->email ?: '',
                $s?->contact_number ?: '',
                $r->award_batch ? preg_replace('/\D/', '', $r->award_batch) : '',
                $amount !== null ? number_format($amount, 2) : 'TO FILL IN',
                '',
                $amount !== null ? number_format($amount, 2) : 'TO FILL IN',
            ];
        })->all();

        $total = $records->sum(fn ($r) => $r->award_amount !== null ? (float) $r->award_amount : (float) ($scholarship->amount ?? 0));
        $period = trim(($data['semester'] ?? '').' AY '.$data['school_year']);
        $title = "Billing statement: {$scholarship->name}, {$period}";
        $note = 'Prepared by the OAS in CSU UniScholar for checking before encoding or submission. Column order follows the CHED TES billing list (Form 2, Annex 2). '
            .'This is not the official CHED form; birthdate and PWD are not kept in the system and must be filled in.';
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($scholarship->short_name ?: $scholarship->name)), '-');
        $name = "billing-statement-{$slug}-{$data['school_year']}.{$data['format']}";

        try {
            if ($data['format'] === 'xlsx') {
                if (!class_exists(\ZipArchive::class)) {
                    throw new \RuntimeException('PHP zip extension is off. Turn on "extension=zip" in php.ini to make Excel files.');
                }
                $path = tempnam(sys_get_temp_dir(), 'bill');
                DemoListFiles::xlsx($path, [$title, 'Caraga State University - Office of Admission and Scholarship', $note,
                    count($rows).' grantee(s); total '.number_format($total, 2)], self::COLUMNS, $rows, 'Billing');
                $bytes = file_get_contents($path);
                @unlink($path);
                $type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            } else {
                if (!class_exists(\Dompdf\Dompdf::class)) {
                    throw new \RuntimeException('The PDF library is not installed. In the backend folder run: composer require dompdf/dompdf');
                }
                $options = new \Dompdf\Options();
                $options->set('isRemoteEnabled', false);
                $options->set('defaultFont', 'Helvetica');
                $options->set('tempDir', sys_get_temp_dir());
                $dompdf = new \Dompdf\Dompdf($options);
                $dompdf->loadHtml(view('pdf.billing-statement', [
                    'title' => $title, 'note' => $note, 'columns' => self::COLUMNS, 'rows' => $rows, 'total' => $total,
                ])->render());
                $dompdf->setPaper('legal', 'landscape');
                $dompdf->render();
                $bytes = $dompdf->output();
                $type = 'application/pdf';
            }
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => $e instanceof \RuntimeException ? $e->getMessage() : 'Unable to make the file: '.$e->getMessage()], 500);
        }

        ActivityLog::record($request->user(), 'billing.exported',
            "Exported the billing statement of {$scholarship->name}, {$period} (".strtoupper($data['format'])."): ".count($rows)
            .' grantee(s), total '.number_format($total, 2).($missing ? ", {$missing} row(s) with award number or amount to fill in" : '').'.');

        return response($bytes, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Rows' => (string) count($rows),
            'X-Missing' => (string) $missing,
        ]);
    }
}
