<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ProcessingBatch;
use App\Models\Scholarship;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProcessingBatchController extends Controller
{
    private function staffOnly(Request $request): void
    {
        if ($request->user()->role !== 'staff') {
            abort(response()->json(['message' => 'Only OAS staff can manage processing batches.'], 403));
        }
    }

    public function index(Request $request)
    {
        $this->staffOnly($request);

        $filters = $request->validate([
            'scholarship_id' => 'nullable|integer',
            'status' => 'nullable|in:open,completed',
            'school_year' => ['nullable', 'regex:/^\d{4}-\d{4}$/'],
        ]);

        $batches = ProcessingBatch::with(['scholarship:id,name,short_name', 'creator:id,name', 'completer:id,name'])
            ->when($filters['scholarship_id'] ?? null, fn ($q, $id) => $q->where('scholarship_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['school_year'] ?? null, fn ($q, $year) => $q->where('school_year', $year))
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->orderByDesc('school_year')
            ->orderByDesc('id')
            ->get()
            ->each(fn (ProcessingBatch $b) => $b->setAttribute('counts', $b->counts()));

        return response()->json([
            'data' => $batches,
            'options' => [
                'scholarships' => Scholarship::orderBy('name')->get(['id', 'name', 'short_name']),
                'semesters' => Setting::SEMESTERS,
                'current_school_year' => \App\Models\ExternalGrantee::currentSchoolYear(),
                'current_semester' => Setting::get('current_semester'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->staffOnly($request);

        $data = $request->validate([
            'scholarship_id' => 'required|exists:scholarships,id',
            'school_year' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'semester' => ['nullable', Rule::in(Setting::SEMESTERS)],
            'name' => 'nullable|string|max:140',
            'notes' => 'nullable|string|max:2000',
        ]);

        [$start, $end] = array_map('intval', explode('-', $data['school_year']));
        if ($end !== $start + 1) {
            return response()->json(['message' => 'The school year must be two years in a row, e.g. 2026-2027.'], 422);
        }

        $scholarship = Scholarship::findOrFail($data['scholarship_id']);
        $name = trim((string) ($data['name'] ?? '')) ?: ProcessingBatch::defaultName($scholarship, $data['school_year'], $data['semester'] ?? null);

        if (ProcessingBatch::where('scholarship_id', $scholarship->id)->where('name', $name)->exists()) {
            return response()->json(['message' => "{$scholarship->name} already has a batch named \"{$name}\". Use another name."], 422);
        }

        $batch = ProcessingBatch::create([
            'scholarship_id' => $scholarship->id,
            'name' => $name,
            'school_year' => $data['school_year'],
            'semester' => $data['semester'] ?? null,
            'status' => 'open',
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        ActivityLog::record($request->user(), 'batch.created', "Created processing batch \"{$batch->name}\" ({$scholarship->name}).", $batch);

        return response()->json([
            'message' => "Batch \"{$batch->name}\" created. New applications and approved lists of {$scholarship->name} for this period go into it.",
            'batch' => $batch->load('scholarship:id,name,short_name')->setAttribute('counts', $batch->counts()),
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $this->staffOnly($request);

        $batch = ProcessingBatch::with(['scholarship:id,name,short_name', 'creator:id,name', 'completer:id,name'])->findOrFail($id);
        $counts = $batch->counts();

        $applications = $batch->applications()
            ->with('student:id,student_id,first_name,middle_name,last_name,course,year_level')
            ->orderBy('status')->orderBy('id')
            ->get(['id', 'student_id', 'scholarship_id', 'school_year', 'semester', 'status', 'enrollment_verified',
                'enrollment_method', 'submitted_at', 'updated_at']);

        $uploads = $batch->uploads()->with('uploader:id,name')->latest()->get()
            ->each(fn ($u) => $u->setAttribute('open_rows', $u->openRowCount()))
            ->map(fn ($u) => collect($u->toArray())->except('details'));

        $grantees = $batch->scholarRecords()
            ->with('student:id,student_id,first_name,last_name')
            ->get(['id', 'student_id', 'scholarship_id', 'school_year', 'semester', 'status', 'currently_enrolled', 'grantee_tagged_at']);

        $payroll = $batch->payrollRecords()
            ->selectRaw('period, status, count(*) as entries, sum(amount) as total')
            ->groupBy('period', 'status')->get();

        return response()->json([
            'batch' => $batch->setAttribute('counts', $counts)->setAttribute('open_items', $batch->openItems($counts)),
            'applications' => $applications,
            'lists' => $uploads,
            'grantees' => $grantees,
            'payroll' => $payroll,
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->staffOnly($request);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:140',
            'notes' => 'nullable|string|max:2000',
        ]);

        $batch = ProcessingBatch::findOrFail($id);
        if (isset($data['name']) && ProcessingBatch::where('scholarship_id', $batch->scholarship_id)
            ->where('name', $data['name'])->where('id', '!=', $batch->id)->exists()) {
            return response()->json(['message' => "This program already has a batch named \"{$data['name']}\"."], 422);
        }

        $batch->update($data);

        return response()->json(['message' => 'Batch updated.', 'batch' => $batch->fresh('scholarship:id,name,short_name')]);
    }

    public function complete(Request $request, $id)
    {
        $this->staffOnly($request);

        $data = $request->validate([
            'confirm_open_items' => 'nullable|boolean',
            'note' => 'nullable|string|max:2000',
        ]);

        $batch = ProcessingBatch::findOrFail($id);
        if ($batch->status === 'completed') {
            return response()->json(['message' => 'This batch is already completed.'], 422);
        }

        $open = $batch->openItems();
        if ($open && empty($data['confirm_open_items'])) {
            return response()->json([
                'message' => 'This batch still has open work: '.implode('; ', $open).'.',
                'open_items' => $open,
                'needs_confirmation' => true,
            ], 409);
        }
        if ($open && trim((string) ($data['note'] ?? '')) === '') {
            return response()->json(['message' => 'Write why the batch is completed although work is still open.'], 422);
        }

        $notes = trim(($batch->notes ? $batch->notes."\n" : '')
            .($open ? 'Completed with open work ('.implode('; ', $open).'): '.trim($data['note']) : ($data['note'] ?? '')));

        $batch->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => $request->user()->id,
            'notes' => $notes !== '' ? $notes : null,
        ]);

        ActivityLog::record($request->user(), 'batch.completed', "Completed processing batch \"{$batch->name}\"."
            .($open ? ' Open work at the time: '.implode('; ', $open).'.' : ''), $batch);

        return response()->json([
            'message' => "Batch \"{$batch->name}\" completed. Its records stay in the system and in the Data Bank.",
            'batch' => $batch->fresh(['scholarship:id,name,short_name', 'completer:id,name'])->setAttribute('counts', $batch->counts()),
        ]);
    }

    public function reopen(Request $request, $id)
    {
        $this->staffOnly($request);

        $batch = ProcessingBatch::findOrFail($id);
        if ($batch->status === 'open') {
            return response()->json(['message' => 'This batch is already open.'], 422);
        }

        $batch->update(['status' => 'open', 'completed_at' => null, 'completed_by' => null]);
        ActivityLog::record($request->user(), 'batch.reopened', "Reopened processing batch \"{$batch->name}\".", $batch);

        return response()->json([
            'message' => "Batch \"{$batch->name}\" reopened.",
            'batch' => $batch->fresh('scholarship:id,name,short_name')->setAttribute('counts', $batch->counts()),
        ]);
    }
}
