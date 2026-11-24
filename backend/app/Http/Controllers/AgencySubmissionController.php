<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AgencySubmission;
use App\Models\Application;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Sending forwarded applications to an agency
|--------------------------------------------------------------------------
| Marking an application "Complete" means OAS finished its review. The list is sent to the agency
| later, usually for many students at once. "Record as sent" saves that one sending (date, how,
| note) and gives every application in it the SAME forwarded date, so the list shows when the
| agency actually received it instead of the day each review was finished.
|
| The supporting-documents ZIP is made only on request and only with the document types staff
| tick (an agency rarely needs every file). Downloads are written to the activity log.
*/
class AgencySubmissionController extends Controller
{
    private function staffOnly(Request $request): void
    {
        if ($request->user()->role !== 'staff') {
            abort(response()->json(['message' => 'Only OAS staff can do this.'], 403));
        }
    }

    // GET /staff/agency-submissions   ?scholarship_id=
    public function index(Request $request)
    {
        $this->staffOnly($request);

        return response()->json(AgencySubmission::with(['scholarship:id,name,short_name', 'processingBatch:id,name', 'creator:id,name'])
            ->when($request->integer('scholarship_id') ?: null, fn ($q, $id) => $q->where('scholarship_id', $id))
            ->latest('sent_on')->latest('id')->limit(200)->get());
    }

    /*
    | POST /staff/agency-submissions
    | Body: ids [application ids], sent_on (date), sent_by_way (optional), note (optional),
    |       confirm_resend (true to move applications that were already recorded as sent)
    */
    public function store(Request $request)
    {
        $this->staffOnly($request);

        $data = $request->validate([
            'ids' => 'required|array|min:1|max:2000',
            'ids.*' => 'integer|distinct',
            'sent_on' => 'required|date|before_or_equal:today',
            'sent_by_way' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:2000',
            'confirm_resend' => 'nullable|boolean',
        ]);

        $apps = Application::with('scholarship:id,name,provider')->whereIn('id', $data['ids'])->get();

        if ($apps->count() !== count($data['ids'])) {
            return response()->json(['message' => 'Some of the selected applications no longer exist. Reload the page.'], 422);
        }
        if ($notReady = $apps->where('status', '!=', 'complete')->count()) {
            return response()->json(['message' => "{$notReady} selected application(s) are not forwarded (Complete) any more. Reload the page."], 422);
        }
        if ($apps->pluck('scholarship_id')->unique()->count() > 1) {
            return response()->json(['message' => 'One sending is for one program. Choose the program tab first, then select its students.'], 422);
        }
        $already = $apps->whereNotNull('agency_submission_id');
        if ($already->isNotEmpty() && !$request->boolean('confirm_resend')) {
            return response()->json([
                'message' => $already->count().' selected application(s) were already recorded as sent. Record them in this new sending instead?',
                'needs_confirmation' => true,
            ], 409);
        }

        $scholarship = $apps->first()->scholarship;
        $batchIds = $apps->pluck('processing_batch_id')->filter()->unique();
        // The date staff chose, at the time of recording (so the order of the day is kept).
        $sentAt = \Illuminate\Support\Carbon::parse($data['sent_on'], 'Asia/Manila')
            ->setTimeFrom(now('Asia/Manila'))->utc();

        $submission = DB::transaction(function () use ($apps, $data, $scholarship, $batchIds, $sentAt, $request) {
            $submission = AgencySubmission::create([
                'scholarship_id' => $scholarship->id,
                'processing_batch_id' => $batchIds->count() === 1 ? $batchIds->first() : null,
                'sent_on' => $data['sent_on'],
                'agency_name' => $scholarship->provider,
                'sent_by_way' => $data['sent_by_way'] ?? null,
                'note' => $data['note'] ?? null,
                'application_count' => $apps->count(),
                'created_by' => $request->user()->id,
            ]);

            Application::whereIn('id', $apps->pluck('id'))->update([
                'agency_submission_id' => $submission->id,
                'forwarded_at' => $sentAt,
            ]);

            return $submission;
        });

        ActivityLog::record($request->user(), 'agency.submission_recorded',
            "Recorded {$apps->count()} application(s) of {$scholarship->name} as sent to ".($scholarship->provider ?: 'the agency')
            ." on {$submission->sent_on->format('M j, Y')}.", $submission);

        return response()->json([
            'message' => "Recorded: {$apps->count()} application(s) sent to ".($scholarship->provider ?: 'the agency')
                ." on {$submission->sent_on->format('F j, Y')}. They all show this date as Forwarded on.",
            'submission' => $submission->load('scholarship:id,name'),
        ], 201);
    }

    /*
    | POST /staff/forwarded/documents-zip   Body: ids [application ids], types [requirement names]
    | One folder per student (LASTNAME_Firstname_StudentID) with only the ticked document types,
    | plus a list of what is inside and what was missing.
    */
    public function documentsZip(Request $request)
    {
        $this->staffOnly($request);

        $data = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
            'types' => 'required|array|min:1|max:50',
            'types.*' => 'string|max:255',
        ]);

        if (!class_exists(\ZipArchive::class)) {
            return response()->json(['message' => 'PHP zip extension is off. Turn on "extension=zip" in php.ini, then restart php artisan serve.'], 500);
        }

        $apps = Application::with(['student:id,student_id,first_name,last_name', 'scholarship:id,name,short_name',
                'documents.requirement:id,name'])
            ->whereIn('id', $data['ids'])
            ->whereIn('status', ['complete', 'approved'])
            ->get();

        if ($apps->isEmpty()) {
            return response()->json(['message' => 'None of these applications is forwarded to an agency.'], 422);
        }

        $types = collect($data['types'])->map(fn ($t) => mb_strtolower(trim($t)))->all();
        $path = tempnam(sys_get_temp_dir(), 'docs');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $index = ["Student ID,Last name,First name,Document,File in this ZIP"];
        $added = 0;
        $missing = 0;
        foreach ($apps as $app) {
            $student = $app->student;
            $folder = preg_replace('/[^A-Za-z0-9_-]+/', '_', mb_strtoupper((string) $student?->last_name).'_'.$student?->first_name.'_'.$student?->student_id);
            foreach ($data['types'] as $type) {
                $doc = $app->documents->first(fn (Document $d) => mb_strtolower($d->typeName()) === mb_strtolower(trim($type)));
                $file = $doc?->absolutePath();
                if ($doc && $file && is_file($file)) {
                    $ext = strtolower(pathinfo($doc->original_filename ?: $file, PATHINFO_EXTENSION)) ?: 'pdf';
                    $name = $folder.'/'.preg_replace('/[^A-Za-z0-9 ()_-]+/', '', $doc->typeName()).'.'.$ext;
                    $zip->addFile($file, $name);
                    $index[] = $this->csv([$student?->student_id, $student?->last_name, $student?->first_name, $doc->typeName(), $name]);
                    $added++;
                } else {
                    $index[] = $this->csv([$student?->student_id, $student?->last_name, $student?->first_name, $type, 'MISSING (not uploaded)']);
                    $missing++;
                }
            }
        }
        $zip->addFromString('00_contents.csv', "\xEF\xBB\xBF".implode("\r\n", $index)."\r\n");
        $zip->close();

        $programs = $apps->pluck('scholarship.short_name')->filter()->unique();
        $slug = $programs->count() === 1 ? trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($programs->first())), '-') : 'all-programs';
        ActivityLog::record($request->user(), 'agency.documents_downloaded',
            "Downloaded a ZIP of supporting documents ({$added} file(s), ".implode(', ', $data['types']).") for {$apps->count()} application(s).");

        return response()->download($path, "supporting-documents-{$slug}-".now('Asia/Manila')->format('Y-m-d').'.zip', [
            'Content-Type' => 'application/zip',
            'X-Files-Added' => (string) $added,
            'X-Files-Missing' => (string) $missing,
        ])->deleteFileAfterSend(true);
    }

    private function csv(array $cells): string
    {
        return implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $cells));
    }
}
