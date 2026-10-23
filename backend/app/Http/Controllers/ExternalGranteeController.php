<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ExternalGrantee;
use App\Models\ExternalGranteeUpload;
use App\Models\Student;
use App\Support\EligibilityEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| External Grantees (staff)
|--------------------------------------------------------------------------
| Students who already hold a scholarship from an OUTSIDE party (LGU, CHED
| list, private foundation). Staff upload the party's list (PDF, Word,
| Excel, CSV or a picture):
|
|   1. upload   the file is kept, and the AI service reads it
|               (/extract-grantee-list): names + Student IDs, and a
|               suggested ScholarGuide student for each line (Student ID
|               first, then RapidFuzz name match >= 80%)
|   2. review   staff confirm or correct every line (status "review")
|   3. save     confirmed lines become external_grantees rows (matched,
|               unmatched and unreadable lines are all kept for the record)
|
| A matched, active holding shows on the student's profile and in the Data
| Bank, and counts for the one-active-scholarship rule (applying, grantee
| tagging, Auto-Review, AI Recommendations).
*/
class ExternalGranteeController extends Controller
{
    private function staffOnly(Request $request): void
    {
        if ($request->user()->role !== 'staff') {
            abort(response()->json(['message' => 'Only OAS staff can manage external grantees.'], 403));
        }
    }

    private function officeOnly(Request $request): void
    {
        if (!$request->user()->isOffice()) {
            abort(response()->json(['message' => 'Unauthorized.'], 403));
        }
    }

    /*
    | GET /staff/external-grantees
    | q (name / Student ID), program, status (active | ended | all),
    | match (matched | unmatched | error | all; default matched), upload_id,
    | page (50 per page), export=1 (everything, for CSV)
    */
    public function index(Request $request)
    {
        $this->officeOnly($request);

        $f = $request->validate([
            'q' => 'nullable|string|max:100',
            'program' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,ended,all',
            'match' => 'nullable|in:matched,unmatched,error,all',
            'upload_id' => 'nullable|integer',
            'page' => 'nullable|integer|min:1',
            'export' => 'nullable|boolean',
        ]);

        $words = array_filter(preg_split('/\s+/', trim((string) ($f['q'] ?? ''))));
        $match = $f['match'] ?? 'matched';
        $status = $f['status'] ?? 'all';

        $query = ExternalGrantee::query()
            ->when($match !== 'all', fn ($q) => $q->where('match_status', $match))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($f['program'] ?? null, fn ($q, $p) => $q->where('program_name', $p))
            ->when($f['upload_id'] ?? null, fn ($q, $id) => $q->where('upload_id', $id))
            ->when($words, function ($q) use ($words) {
                foreach ($words as $word) {
                    $like = '%'.$word.'%';
                    $q->where(fn ($w) => $w->where('extracted_name', 'like', $like)
                        ->orWhere('extracted_student_id', 'like', $like)
                        ->orWhereHas('student', fn ($s) => $s->where('student_id', 'like', $like)
                            ->orWhere('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)));
                }
            });

        $total = (clone $query)->count();
        $export = (bool) ($f['export'] ?? false);
        $perPage = $export ? 5000 : 50;
        $page = $export ? 1 : (int) ($f['page'] ?? 1);

        $rows = $query->with([
            'student:id,student_id,first_name,middle_name,last_name,course,year_level,college',
            'upload:id,file_name,created_at',
            'confirmer:id,name',
        ])
            ->orderBy('program_name')
            ->orderBy('extracted_name')
            ->skip(($page - 1) * $perPage)->take($perPage)
            ->get();

        return response()->json([
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'has_more' => !$export && $page * $perPage < $total,
            'programs' => ExternalGrantee::query()
                ->select('program_name', DB::raw('count(*) as total'))
                ->where('match_status', 'matched')->where('status', 'active')
                ->groupBy('program_name')->orderBy('program_name')
                ->get(),
        ]);
    }

    // GET /staff/external-grantees/uploads   History of uploaded files (newest first)
    public function uploads(Request $request)
    {
        $this->officeOnly($request);

        return response()->json(
            ExternalGranteeUpload::with(['uploader:id,name', 'saver:id,name'])
                ->latest()->latest('id')
                ->limit(200)
                ->get()
                ->makeHidden('rows')
        );
    }

    // GET /staff/external-grantees/uploads/{id}   One upload with what the AI read
    public function showUpload(Request $request, $id)
    {
        $this->officeOnly($request);

        return response()->json(ExternalGranteeUpload::with(['uploader:id,name', 'saver:id,name'])->findOrFail($id));
    }

    /*
    | POST /staff/external-grantees/uploads   (multipart)
    | file, program_name, agency, category, school_year
    */
    public function store(Request $request)
    {
        $this->staffOnly($request);

        $data = $request->validate([
            'file' => 'required|file|max:20480|extensions:pdf,docx,xlsx,csv,png,jpg,jpeg',
            'program_name' => 'required|string|max:255',
            'agency' => 'nullable|string|max:255',
            'category' => 'nullable|in:'.implode(',', array_keys(ExternalGranteeUpload::CATEGORIES)),
            'school_year' => ['nullable', 'regex:/^\d{4}-\d{4}$/'],
        ], [
            'file.extensions' => 'Upload a PDF, Word (.docx), Excel (.xlsx), CSV, PNG or JPG file. Old .doc / .xls files: open them and "Save As" .docx / .xlsx first.',
            'school_year.regex' => 'Write the school year like 2026-2027.',
        ]);

        $file = $request->file('file');
        $original = $file->getClientOriginalName();
        $type = strtolower($file->getClientOriginalExtension() ?: $file->extension());

        $students = Student::query()
            ->get(['id', 'student_id', 'first_name', 'middle_name', 'last_name'])
            ->map(fn ($s) => $s->only(['id', 'student_id', 'first_name', 'middle_name', 'last_name']))
            ->values();

        try {
            $response = Http::timeout(300)
                ->attach('file', file_get_contents($file->getRealPath()), $original)
                ->post(rtrim(config('services.ai.url'), '/').'/extract-grantee-list', [
                    'students' => json_encode($students),
                    'threshold' => 80,
                ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'The AI service is not running, so the file could not be read. Start it (Terminal 2) and upload again.',
            ], 503);
        }

        if (!$response->successful()) {
            return response()->json([
                'message' => $response->json('detail') ?: 'The AI service could not read this file.',
            ], $response->status() === 422 ? 422 : 502);
        }

        $result = $response->json();
        $path = $file->storeAs('external-lists', now()->format('Ymd-His').'-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $original), 'local');
        $summary = $result['summary'] ?? [];

        $upload = ExternalGranteeUpload::create([
            'program_name' => trim($data['program_name']),
            'agency' => $data['agency'] ?? null,
            'category' => $data['category'] ?? null,
            'school_year' => $data['school_year'] ?? null,
            'file_name' => $original,
            'file_path' => $path,
            'file_type' => $type,
            'status' => 'review',
            'read_method' => $result['method'] ?? null,
            'warnings' => $result['warnings'] ?? [],
            'rows' => $result['rows'] ?? [],
            'total_rows' => $summary['total'] ?? 0,
            'matched_count' => $summary['matched'] ?? 0,
            'check_count' => $summary['check'] ?? 0,
            'unmatched_count' => $summary['unmatched'] ?? 0,
            'error_count' => $summary['error'] ?? 0,
            'uploaded_by' => $request->user()->id,
        ]);

        ActivityLog::record($request->user(), 'external_list.uploaded',
            "Uploaded external grantee list {$original} ({$upload->program_name}): {$upload->total_rows} lines read.", $upload);

        return response()->json([
            'message' => "Read {$upload->total_rows} line(s): {$upload->matched_count} matched, {$upload->check_count} to check, "
                ."{$upload->unmatched_count} did not match, {$upload->error_count} error(s). Review them, then press Save.",
            'upload' => $upload->load('uploader:id,name'),
        ], 201);
    }

    /*
    | POST /staff/external-grantees/uploads/{id}/save
    | decisions: [{ row, student_id (ScholarGuide students.id or null), include (bool) }]
    | One decision per line the AI read. include=false leaves the line out
    | (e.g. a title the AI mistook for a name).
    */
    public function save(Request $request, $id)
    {
        $this->staffOnly($request);

        $upload = ExternalGranteeUpload::findOrFail($id);
        if ($upload->status !== 'review') {
            return response()->json(['message' => 'This upload was already '.$upload->status.'.'], 422);
        }

        $decisions = collect($request->validate([
            'decisions' => 'required|array',
            'decisions.*.row' => 'required|integer',
            'decisions.*.student_id' => 'nullable|integer|exists:students,id',
            'decisions.*.include' => 'required|boolean',
        ])['decisions'])->keyBy('row');

        $picked = $decisions->where('include', true)->pluck('student_id')->filter();
        if ($picked->count() !== $picked->unique()->count()) {
            return response()->json(['message' => 'The same student is linked to two lines. Each student can be linked once.'], 422);
        }

        $counts = ['matched' => 0, 'unmatched' => 0, 'error' => 0, 'skipped' => 0, 'already' => 0];
        $touched = [];

        DB::transaction(function () use ($upload, $decisions, $request, &$counts, &$touched) {
            foreach ($upload->rows ?? [] as $line) {
                $decision = $decisions[$line['row']] ?? null;
                if (!$decision || !$decision['include']) {
                    $counts['skipped']++;
                    continue;
                }

                $studentId = $decision['student_id'] ?? null;
                $aiStudent = $line['result'] === 'matched' ? ($line['student']['id'] ?? null) : null;
                $status = $studentId ? 'matched' : (($line['result'] ?? '') === 'error' ? 'error' : 'unmatched');

                if ($studentId && ExternalGrantee::activeFor($studentId)->where('program_name', $upload->program_name)->exists()) {
                    $counts['already']++;   // recorded by an earlier list; keep that one
                    continue;
                }

                ExternalGrantee::create([
                    'upload_id' => $upload->id,
                    'student_id' => $studentId,
                    'row_number' => $line['row'],
                    'extracted_name' => $line['name'] ?: null,
                    'extracted_student_id' => $line['student_id'] ?: null,
                    'program_name' => $upload->program_name,
                    'agency' => $upload->agency,
                    'category' => $upload->category,
                    'school_year' => $upload->school_year,
                    'match_status' => $status,
                    'match_method' => $studentId ? ((int) $studentId === (int) $aiStudent ? ($line['method'] ?? 'name') : 'manual') : null,
                    'match_score' => $studentId && (int) $studentId === (int) $aiStudent ? ($line['score'] ?? null) : null,
                    'status' => 'active',
                    'note' => $line['note'] ?? null,
                    'confirmed_by' => $request->user()->id,
                ]);

                $counts[$status]++;
                if ($studentId) {
                    $touched[] = $studentId;
                }
            }

            $upload->update([
                'status' => 'saved',
                'saved_by' => $request->user()->id,
                'saved_at' => now(),
                'matched_count' => $counts['matched'] + $counts['already'],
                'check_count' => 0,
                'unmatched_count' => $counts['unmatched'],
                'error_count' => $counts['error'],
            ]);
        });

        if ($touched) {
            EligibilityEngine::recalculate(array_values(array_unique($touched)));
        }

        ActivityLog::record($request->user(), 'external_list.saved',
            "Saved external grantee list {$upload->file_name} ({$upload->program_name}): {$counts['matched']} matched, {$counts['unmatched']} unmatched, {$counts['error']} error(s), {$counts['skipped']} left out.", $upload);

        return response()->json([
            'message' => "Saved. {$counts['matched']} student(s) now hold {$upload->program_name}"
                .($counts['already'] ? ", {$counts['already']} were already recorded" : '')
                .". {$counts['unmatched']} unmatched and {$counts['error']} unreadable line(s) were kept for the record.",
            'counts' => $counts,
            'upload' => $upload->fresh(['uploader:id,name', 'saver:id,name']),
        ]);
    }

    // POST /staff/external-grantees/uploads/{id}/discard
    public function discard(Request $request, $id)
    {
        $this->staffOnly($request);

        $upload = ExternalGranteeUpload::findOrFail($id);
        if ($upload->status !== 'review') {
            return response()->json(['message' => 'Only an upload that is still being reviewed can be discarded.'], 422);
        }

        $upload->update(['status' => 'discarded']);
        ActivityLog::record($request->user(), 'external_list.discarded', "Discarded external grantee list {$upload->file_name}.", $upload);

        return response()->json(['message' => 'Upload discarded. Nothing was saved.']);
    }

    // GET /staff/external-grantees/uploads/{id}/file   The original file, for the record
    public function download(Request $request, $id)
    {
        $this->officeOnly($request);

        $upload = ExternalGranteeUpload::findOrFail($id);
        if (!$upload->file_path || !Storage::disk('local')->exists($upload->file_path)) {
            return response()->json(['message' => 'The original file is no longer stored.'], 404);
        }

        return Storage::disk('local')->download($upload->file_path, $upload->file_name);
    }

    /*
    | PATCH /staff/external-grantees/{id}   Body: status (active | ended), note
    | "Ended": the scholarship finished; it no longer counts for the
    | one-active-scholarship rule.
    */
    public function update(Request $request, $id)
    {
        $this->staffOnly($request);

        $data = $request->validate([
            'status' => 'required|in:active,ended',
            'note' => 'nullable|string|max:1000',
        ]);

        $grantee = ExternalGrantee::with('student')->findOrFail($id);
        if ($grantee->match_status !== 'matched') {
            return response()->json(['message' => 'Only lines linked to a student can be changed.'], 422);
        }

        $grantee->update([
            'status' => $data['status'],
            'ended_at' => $data['status'] === 'ended' ? now() : null,
            'note' => $data['note'] ?? $grantee->note,
        ]);

        EligibilityEngine::recalculate([$grantee->student_id]);
        ActivityLog::record($request->user(), 'external_grantee.'.$data['status'],
            ($data['status'] === 'ended' ? 'Ended' : 'Re-activated')." the external scholarship {$grantee->program_name} of ".($grantee->student?->fullName() ?? 'a student').'.', $grantee);

        return response()->json([
            'message' => $data['status'] === 'ended' ? 'Marked as ended.' : 'Marked as active again.',
            'grantee' => $grantee->fresh(['student:id,student_id,first_name,middle_name,last_name']),
        ]);
    }

    /*
    | POST /staff/read-table   (multipart: file)
    | Any list file (PDF, Word .docx, Excel .xlsx, CSV, PNG/JPG) -> rows of
    | cells, read by the AI service. Used by Approved Lists and Enrollment,
    | where staff then choose the Student ID column (CSV files are read in
    | the browser and do not come here).
    */
    public function readTable(Request $request)
    {
        $this->staffOnly($request);

        $request->validate([
            'file' => 'required|file|max:20480|extensions:pdf,docx,xlsx,csv,png,jpg,jpeg',
        ], [
            'file.extensions' => 'Upload a PDF, Word (.docx), Excel (.xlsx), CSV, PNG or JPG file. Old .doc / .xls files: open them and "Save As" .docx / .xlsx first.',
        ]);

        $file = $request->file('file');

        try {
            $response = Http::timeout(300)
                ->attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
                ->post(rtrim(config('services.ai.url'), '/').'/read-table');
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'The AI service is not running, so this file could not be read. Start it, or save the list as CSV.',
            ], 503);
        }

        if (!$response->successful()) {
            return response()->json(['message' => $response->json('detail') ?: 'The file could not be read.'], 422);
        }

        return response()->json([
            'rows' => $response->json('rows') ?? [],
            'method' => $response->json('method'),
            'warnings' => $response->json('warnings') ?? [],
        ]);
    }
}
