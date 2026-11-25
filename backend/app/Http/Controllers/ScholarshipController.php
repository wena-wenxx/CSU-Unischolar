<?php

namespace App\Http\Controllers;

use App\Support\EligibilityEngine;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\Scholarship;
use App\Models\ScholarshipRequirement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScholarshipController extends Controller
{
    private function notStaff(Request $request): bool
    {
        return !$request->user()->isOffice();
    }

    public function index(Request $request)
    {
        $query = Scholarship::with('requirements');

        if ($request->user()->role === 'student') {
            $query->visibleToStudents()
                ->orderByRaw('application_end is null')
                ->orderBy('application_end')
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
            'short_name' => 'nullable|string|max:40',
            'application_mode' => 'nullable|in:'.implode(',', Scholarship::MODES),
            'description' => 'nullable|string',
            'provider' => 'nullable|string|max:255',
            'category' => 'nullable|in:'.implode(',', Scholarship::CATEGORIES),
            'application_start' => 'nullable|date',
            'application_end' => 'nullable|date|after_or_equal:application_start',
            'amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:active,inactive,closed',
            'max_gwa' => 'nullable|numeric|min:1|max:5',
            'max_family_income' => 'nullable|numeric|min:0|max:100000000',
            'year_levels' => 'nullable|array|max:10',
            'year_levels.*' => 'string|max:50',
            'colleges' => 'nullable|array|max:30',
            'colleges.*' => 'string|max:255',
            'one_scholarship_only' => 'nullable|boolean',
            'rules_source' => 'nullable|string|max:3000',
        ];
    }

    private const RULE_FIELDS = ['max_gwa', 'max_family_income', 'year_levels', 'colleges', 'one_scholarship_only'];

    private const RULE_LABELS = [
        'max_gwa' => 'highest GWA', 'max_family_income' => 'income ceiling', 'year_levels' => 'year levels',
        'colleges' => 'colleges', 'one_scholarship_only' => 'one scholarship only',
    ];

    private static function ruleText(string $field, $value): string
    {
        return match (true) {
            $value === null || $value === [] => 'none',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => implode(', ', $value),
            $field === 'max_gwa' => number_format((float) $value, 2),
            $field === 'max_family_income' => 'PHP '.number_format((float) $value, 0),
            default => (string) $value,
        };
    }

    private function cleanRules(array $data): array
    {
        foreach (['year_levels', 'colleges'] as $key) {
            if (array_key_exists($key, $data)) {
                $list = array_values(array_unique(array_filter(array_map('trim', $data[$key] ?? []))));
                $data[$key] = $list ?: null;
            }
        }
        if (array_key_exists('one_scholarship_only', $data) && $data['one_scholarship_only'] === null) {
            unset($data['one_scholarship_only']);
        }

        return $data;
    }

    public function eligibilityOptions(Request $request)
    {
        if ($this->notStaff($request)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'year_levels' => \App\Models\Student::query()->whereNotNull('year_level')->distinct()->orderBy('year_level')->pluck('year_level'),
            'colleges' => \App\Models\Student::query()->whereNotNull('college')->distinct()->orderBy('college')->pluck('college'),
        ]);
    }

    public function requirementTypes()
    {
        return response()->json(collect(StudentDocumentController::STANDARD_TYPES)->map(fn ($name) => [
            'name' => $name,
            'validity_months' => Document::VALIDITY_MONTHS[$name] ?? null,
        ])->values());
    }

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
        $data['application_mode'] = $data['application_mode'] ?? 'oas';
        $data = $this->cleanRules($data);

        $requirements = $data['requirements'] ?? [];
        unset($data['requirements']);
        if (collect(self::RULE_FIELDS)->contains(fn ($f) => !in_array($data[$f] ?? null, [null, [], false], true))) {
            $data['rules_updated_by'] = $request->user()->id;
            $data['rules_updated_at'] = now();
        }

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

        $data = $this->cleanRules($request->validate($this->rules(false)));

        $scholarship = Scholarship::findOrFail($id);
        $before = $scholarship->only(self::RULE_FIELDS);
        $scholarship->fill($data);
        $changed = array_keys($scholarship->getDirty());

        $ruleChanges = array_values(array_intersect($changed, self::RULE_FIELDS));
        if ($ruleChanges) {
            $scholarship->rules_updated_by = $request->user()->id;
            $scholarship->rules_updated_at = now();
        }
        $scholarship->save();

        if ($ruleChanges) {
            $parts = array_map(fn ($f) => self::RULE_LABELS[$f].': '.self::ruleText($f, $before[$f]).' → '.self::ruleText($f, $scholarship->{$f}), $ruleChanges);
            ActivityLog::record($request->user(), 'scholarship.rules_changed',
                "Changed the eligibility rules of {$scholarship->name}: ".implode('; ', $parts).'.', $scholarship);
            EligibilityEngine::recalculate();
        }
        $other = array_diff($changed, self::RULE_FIELDS, ['rules_source']);
        if ($other) {
            ActivityLog::record($request->user(), 'scholarship.updated', "Edited scholarship {$scholarship->name}: ".implode(', ', $other).'.', $scholarship);
        }
        if (in_array('rules_source', $changed, true)) {
            ActivityLog::record($request->user(), 'scholarship.rules_source', "Updated where the rules of {$scholarship->name} come from.", $scholarship);
        }

        return response()->json($scholarship);
    }

    public function destroy(Request $request, $id)
    {
        if ($this->notStaff($request)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $scholarship = Scholarship::withCount('applications')->findOrFail($id);

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
        $requirement->scholarship?->touch();
        ActivityLog::record($request->user(), 'scholarship.requirement_removed', "Removed requirement \"{$requirement->name}\" from {$requirement->scholarship?->name}.");

        return response()->json(['message' => 'Requirement deleted successfully.']);
    }
}
