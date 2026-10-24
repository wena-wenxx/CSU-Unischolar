<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Application;
use App\Support\DemoListFiles;
use Illuminate\Http\Request;

/*
| POST /staff/forwarded/export  (round 12)
| Forwarded to Agency as Excel (.xlsx) or PDF, besides the CSV made in the
| browser. Same student details as the CSV. It is a plain list of what OAS
| keeps, NOT any agency's official template (none was given to us).
*/
class ForwardedExportController extends Controller
{
    private const COLUMNS = ['Student ID', 'Last name', 'First name', 'Middle name', 'Sex', 'Course', 'Year level',
        'College', 'Contact number', 'Email', 'Scholarship', 'Agency', 'Forwarded on'];

    public function export(Request $request)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json(['message' => 'Only OAS staff can export this list.'], 403);
        }

        $data = $request->validate([
            'format' => 'required|in:xlsx,pdf',
            'ids' => 'required|array|min:1|max:2000',
            'ids.*' => 'integer',
            'title' => 'nullable|string|max:150',
        ]);

        $apps = Application::with(['student.user:id,email', 'scholarship:id,name,short_name,provider'])
            ->whereIn('id', $data['ids'])
            ->where('status', 'complete')
            ->get()
            ->sortBy(fn ($a) => mb_strtolower(($a->scholarship?->name ?? '').' '.$a->student?->last_name.' '.$a->student?->first_name))
            ->values();

        if ($apps->isEmpty()) {
            return response()->json(['message' => 'None of these applications is still forwarded to an agency.'], 422);
        }

        $rows = $apps->map(fn ($a) => [
            $a->student?->student_id, $a->student?->last_name, $a->student?->first_name, $a->student?->middle_name ?: '',
            $a->student?->sex ?: '', $a->student?->course ?: '', $a->student?->year_level ?: '', $a->student?->college ?: '',
            $a->student?->contact_number ?: '', $a->student?->user?->email ?: '', $a->scholarship?->name, $a->scholarship?->provider ?: '',
            $a->forwarded_at ? $a->forwarded_at->format('M j, Y') : '',
        ])->all();

        $title = ($data['title'] ?? null) ?: 'Applications forwarded to the agency';
        $programs = $apps->pluck('scholarship.short_name')->filter()->unique();
        $slug = $programs->count() === 1 ? trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($programs->first())), '-') : 'all-programs';
        $name = "forwarded-{$slug}-".now('Asia/Manila')->format('Y-m-d').'.'.$data['format'];

        try {
            if ($data['format'] === 'xlsx') {
                if (!class_exists(\ZipArchive::class)) {
                    throw new \RuntimeException('PHP zip extension is off. Turn on "extension=zip" in php.ini to make Excel files.');
                }
                $path = tempnam(sys_get_temp_dir(), 'fwd');
                DemoListFiles::xlsx($path, [$title, 'Caraga State University - Office of Admission and Scholarship', 'Exported '.now('Asia/Manila')->format('F j, Y g:i A')],
                    self::COLUMNS, $rows, 'Forwarded');
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
                $dompdf->loadHtml(view('pdf.forwarded-list', ['title' => $title, 'apps' => $apps])->render());
                $dompdf->setPaper('A4', 'landscape');
                $dompdf->render();
                $bytes = $dompdf->output();
                $type = 'application/pdf';
            }
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => $e instanceof \RuntimeException ? $e->getMessage() : 'Unable to make the file: '.$e->getMessage()], 500);
        }

        ActivityLog::record($request->user(), 'applications.forwarded_exported',
            'Exported '.count($rows).' forwarded application(s) as '.strtoupper($data['format']).'.');

        return response($bytes, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }
}
