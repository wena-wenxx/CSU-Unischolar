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

    // List scholarships. Staff see all; students see only active ones.
    public function index(Request $request)
    {
        $query = Scholarship::with('requirements')->orderBy('id', 'desc');
        if ($request->user()->role === 'student') {
            $query->where('status', 'active');
        }

        return response()->json($query->get());
    }

    public function show($id)
    {
        return response()->json(Scholarship::with('requirements')->findOrFail($id));
    }

    public function store(Request $request)
    {
        if ($this->notStaff($request)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'provider' => 'nullable|string|max:255',
            'application_start' => 'nullable|date',
            'application_end' => 'nullable|date|after_or_equal:application_start',
            'amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:active,inactive,closed',
        ]);

        return response()->json(Scholarship::create($data), 201);
    }

    public function update(Request $request, $id)
    {
        if ($this->notStaff($request)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'provider' => 'nullable|string|max:255',
            'application_start' => 'nullable|date',
            'application_end' => 'nullable|date|after_or_equal:application_start',
            'amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:active,inactive,closed',
        ]);

        $scholarship = Scholarship::findOrFail($id);
        $scholarship->update($data);

        return response()->json($scholarship);
    }

    public function destroy(Request $request, $id)
    {
        if ($this->notStaff($request)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        Scholarship::findOrFail($id)->delete();

        return response()->json(['message' => 'Scholarship deleted']);
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
