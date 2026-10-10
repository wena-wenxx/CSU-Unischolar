<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;

/** The centralized scholarship data bank: search a student, see full history. */
class DataBankController extends Controller
{
    private function staffOnly(Request $request)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return null;
    }

    /*
    | GET /staff/data-bank
    | q            words that must all match the student ID, a name or the course
    | college, year_level, sex
    | standing     active (holds a scholarship now) | former (held one before)
    |              | applied (applied, never a grantee) | none (never applied)
    | scholarship_id  applied to, or held, this program
    | page, per_page  "Show more" paging (25 per page, alphabetical by last name)
    | export=1     every match at once (for the CSV export, up to 5000)
    */
    public function search(Request $request)
    {
        if ($denied = $this->staffOnly($request)) {
            return $denied;
        }

        $filters = $request->validate([
            'q' => 'nullable|string|max:200',
            'college' => 'nullable|string|max:255',
            'year_level' => 'nullable|string|max:255',
            'sex' => 'nullable|in:Female,Male',
            'standing' => 'nullable|in:active,former,applied,none',
            'scholarship_id' => 'nullable|integer',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:5|max:100',
            'export' => 'nullable|boolean',
        ]);

        // Every word typed must match the student ID, a name or the course.
        // "juan student" finds Juan ... Student. Works on MySQL and SQLite.
        $words = array_filter(preg_split('/\s+/', trim((string) ($filters['q'] ?? ''))));

        $query = Student::query()
            ->when($words, function ($query) use ($words) {
                foreach ($words as $word) {
                    $like = '%'.$word.'%';
                    $query->where(function ($w) use ($like) {
                        $w->where('student_id', 'like', $like)
                            ->orWhere('first_name', 'like', $like)
                            ->orWhere('middle_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('course', 'like', $like);
                    });
                }
            })
            ->when($filters['college'] ?? null, fn ($q, $v) => $q->where('college', $v))
            ->when($filters['year_level'] ?? null, fn ($q, $v) => $q->where('year_level', $v))
            ->when($filters['sex'] ?? null, fn ($q, $v) => $q->where('sex', $v))
            ->when($filters['scholarship_id'] ?? null, fn ($q, $id) => $q->where(fn ($w) => $w
                ->whereHas('applications', fn ($a) => $a->where('scholarship_id', $id))
                ->orWhereHas('scholarRecords', fn ($r) => $r->where('scholarship_id', $id))))
            ->when($filters['standing'] ?? null, function ($q, $standing) {
                $submitted = fn ($a) => $a->where('status', '!=', 'draft');
                match ($standing) {
                    'active' => $q->whereHas('scholarRecords', fn ($r) => $r->where('status', 'active')),
                    'former' => $q->whereHas('scholarRecords')
                        ->whereDoesntHave('scholarRecords', fn ($r) => $r->where('status', 'active')),
                    'applied' => $q->whereHas('applications', $submitted)->whereDoesntHave('scholarRecords'),
                    'none' => $q->whereDoesntHave('applications', $submitted)->whereDoesntHave('scholarRecords'),
                };
            });

        $total = (clone $query)->count();
        $export = (bool) ($filters['export'] ?? false);
        $perPage = $export ? 5000 : (int) ($filters['per_page'] ?? 25);
        $page = $export ? 1 : (int) ($filters['page'] ?? 1);

        $students = $query
            ->with(['user:id,email', 'applications:id,student_id,scholarship_id,status', 'applications.scholarship:id,name',
                'scholarRecords:id,student_id,scholarship_id,status', 'scholarRecords.scholarship:id,name'])
            ->orderBy('last_name')->orderBy('first_name')->orderBy('student_id')
            ->skip(($page - 1) * $perPage)->take($perPage)
            ->get()
            ->each(fn ($s) => $s->setAttribute('has_active_scholarship', $s->scholarRecords->where('status', 'active')->isNotEmpty()));

        $response = [
            'data' => $students,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => !$export && $page * $perPage < $total,
        ];

        // Choices for the filter dropdowns (first page only).
        if ($page === 1) {
            $response['options'] = [
                'colleges' => Student::query()->whereNotNull('college')->distinct()->orderBy('college')->pluck('college'),
                'year_levels' => Student::query()->whereNotNull('year_level')->distinct()->orderBy('year_level')->pluck('year_level'),
            ];
        }

        return response()->json($response);
    }

    public function show(Request $request, $id)
    {
        if ($denied = $this->staffOnly($request)) {
            return $denied;
        }

        $student = Student::with([
            'user', 'applications.scholarship', 'applications.documents.validationResult',
            'scholarRecords.scholarship', 'scholarRecords.payrollRecords',
        ])->findOrFail($id);

        $student->setAttribute('has_active_scholarship', $student->scholarRecords->where('status', 'active')->isNotEmpty());

        return response()->json($student);
    }
}
