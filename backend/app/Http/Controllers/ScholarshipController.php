<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\Scholarship;
use App\Models\ScholarshipRequirement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScholarshipController extends Controller
{
    // Staff and the admin may manage scholarship programs.
    private function notStaff(Request $request): bool
    {
        return !$request->user()->isOffice();
    }

    // List scholarships. Staff see all. Students see active programs that
    // are open or opening soon; programs past their deadline are hidden.
    // (A student can still open any program from their own applications.)
    public function index(Request $request)
    {
        $query = Scholarship::with('requirements');

        if ($request->user()->role === 'student') {
            $query->visibleToStudents()
                ->orderByRaw('application_end is null')   // dated deadlines first
                ->orderBy('application_end')               // closing soonest first
                ->orderBy('name');
        } else {
            $query->orderBy('id', 'desc');
        }

        return response()->json($query->get());
    }

    public function show($id)
    {
        return response()->json(
            Scholarship::with('requirements')->withCount('applications')->findOrFail($id)
        );
    }

    private function rules(bool $creating): array
    {
        return [
            'name' => ($creating ? 'required' : 'sometimes|required').'|string|max:255',
            'description' => 'nullable|string',
            'provider' => 'nullable|string|max:255',
            'category' => 'nullable|in:'.implode(',', Scholarship::CATEGORIES),
            'application_start' => 'nullable|date',
            'application_end' => 'nullable|date|after_or_equal:application_start',
            'amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:active,inactive,closed',
        ];
    }

    /*
    | GET /requirement-types
    | The standard document types students keep in My Documents. Requirements
    | picked from this list can be reused across applications and have an
    | expiry period; a custom (typed) requirement cannot.
    */
    public function requirementTypes()
    {
        return response()->json(collect(StudentDocumentController::STANDARD_TYPES)->map(fn ($name) => [
            'name' => $name,
            'validity_months' => Document::VALIDITY_MONTHS[$name] ?? null,
        ])->values());
    }

    /*
    | POST /scholarships
    | The program and its requirements are saved together.
    | requirements: [{ name, is_required, description }]
    */
    public function store(Request $request)
    {
        if ($this->notStaff($request)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate($this->rules(true) + [
            'requirements' => 'nullable|array|max:30',
            'requirements.*.name' => 'required|string|max:255|distinct',
            'requirements.*.is_required' => 'nullable|boolean',
            'requirements.*.description' => 'nullable|string|max:1000',
        ]);
        $data['status'] = $data['status'] ?? 'active';

        $requirements = $data['requirements'] ?? [];
        unset($data['requirements']);

        $scholarship = DB::transaction(function () use ($data, $requirements) {
            $scholarship = Scholarship::create($data);

            foreach ($requirements as $requirement) {
                ScholarshipRequirement::create([
                    'scholarship_id' => $scholarship->id,
                    'name' => trim($requirement['name']),
                    'description' => $requirement['description'] ?? null,
                    'is_required' => $requirement['is_required'] ?? true,
                ]);
            }

            return $scholarship;
        });

        ActivityLog::record($request->user(), 'scholarship.created', "Created scholarship {$scholarship->name} with ".count($requirements).' requirement(s).', $scholarship);

        return response()->json($scholarship->load('requirements'), 201);
    }

    public function update(Request $request, $id)
    {
        if ($this->notStaff($request)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate($this->rules(false));

        $scholarship = Scholarship::findOrFail($id);
        $scholarship->fill($data);
        $changed = array_keys($scholarship->getDirty());
        $scholarship->save();

        if ($changed) {
            ActivityLog::record($request->user(), 'scholarship.updated', "Edited scholarship {$scholarship->name}: ".implode(', ', $changed).'.', $scholarship);
        }

        return response()->json($scholarship);
    }

    public function destroy(Request $request, $id)
    {
        if ($this->notStaff($request)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $scholarship = Scholarship::withCount('applications')->findOrFail($id);

        // Deleting would also delete every application, scholar record and
        // payroll entry under it (database cascade). Keep the history instead.
        if ($scholarship->applications_count > 0) {
            return response()->json([
                'message' => "This scholarship has {$scholarship->applications_count} application(s), so it cannot be deleted. Set its status to Closed instead; students will no longer be able to apply, and the records stay."
            ], 422);
        }

        $scholarship->delete();
        ActivityLog::record($request->user(), 'scholarship.deleted', "Deleted scholarship {$scholarship->name}.");

        return response()->json(['message' => 'Scholarship deleted.']);
    }


    public function addRequirement(Request $request, $id)
    {
        if ($this->notStaff($request)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $scholarship = Scholarship::findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_required' => 'nullable|boolean',
        ]);

        $alreadyListed = $scholarship->requirements()
            ->where('name', $data['name'])
            ->exists();

        if ($alreadyListed) {
            return response()->json([
                'message' => 'This scholarship already lists that requirement.'
            ], 422);
        }

        $requirement = ScholarshipRequirement::create([
            'scholarship_id' => $scholarship->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_required' => $data['is_required'] ?? true,
        ]);

        ActivityLog::record($request->user(), 'scholarship.requirement_added', "Added requirement \"{$requirement->name}\" to {$scholarship->name}.", $scholarship);

        return response()->json($requirement, 201);
    }

    // PATCH /requirements/{id}: switch Required / Optional, or change the note.
    public function updateRequirement(Request $request, $id)
    {
        if ($this->notStaff($request)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'is_required' => 'sometimes|boolean',
            'description' => 'nullable|string|max:1000',
        ]);

        $requirement = ScholarshipRequirement::with('scholarship:id,name')->findOrFail($id);
        $requirement->update($data);
        ActivityLog::record($request->user(), 'scholarship.requirement_changed',
            "Changed requirement \"{$requirement->name}\" of {$requirement->scholarship?->name}".(array_key_exists('is_required', $data) ? ' to '.($data['is_required'] ? 'required' : 'optional') : '').'.');

        return response()->json($requirement);
    }

    public function destroyRequirement(Request $request, $id)
    {
        if ($this->notStaff($request)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $requirement = ScholarshipRequirement::with('scholarship:id,name')->findOrFail($id);
        $requirement->delete();
        ActivityLog::record($request->user(), 'scholarship.requirement_removed', "Removed requirement \"{$requirement->name}\" from {$requirement->scholarship?->name}.");

        return response()->json(['message' => 'Requirement deleted successfully.']);
    }
}
