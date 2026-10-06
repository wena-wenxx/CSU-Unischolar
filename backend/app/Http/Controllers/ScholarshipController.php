<?php

namespace App\Http\Controllers;

use App\Models\Scholarship;
use App\Models\ScholarshipRequirement;
use Illuminate\Http\Request;

class ScholarshipController extends Controller
{
    private function notStaff(Request $request): bool
    {
        return $request->user()->role !== 'staff';
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

    public function store(Request $request)
    {
        if ($this->notStaff($request)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate($this->rules(true));
        $data['status'] = $data['status'] ?? 'active';

        return response()->json(Scholarship::create($data), 201);
    }

    public function update(Request $request, $id)
    {
        if ($this->notStaff($request)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate($this->rules(false));

        $scholarship = Scholarship::findOrFail($id);
        $scholarship->update($data);

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

        return response()->json($requirement, 201);
    }

    public function destroyRequirement(Request $request, $id)
    {
        if ($this->notStaff($request)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        ScholarshipRequirement::findOrFail($id)->delete();

        return response()->json(['message' => 'Requirement deleted successfully.']);
    }
}
