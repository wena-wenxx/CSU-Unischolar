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

    public function search(Request $request)
    {
        if ($denied = $this->staffOnly($request)) {
            return $denied;
        }

        $q = trim((string) $request->query('q', ''));

        $students = Student::query()
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';
                $query->where(function ($w) use ($like) {
                    $w->where('student_id', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('course', 'like', $like)
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", [$like]);
                });
            })
            ->with(['applications.scholarship', 'scholarRecords.scholarship'])
            ->orderBy('last_name')->limit(50)->get()
            ->each(fn ($s) => $s->setAttribute('has_active_scholarship', $s->scholarRecords->where('status', 'active')->isNotEmpty()));

        return response()->json($students);
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
