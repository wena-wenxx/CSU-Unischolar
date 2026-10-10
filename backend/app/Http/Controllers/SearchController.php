<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Scholarship;
use App\Models\Student;
use Illuminate\Http\Request;

/*
| GET /search?q=juan   (the search box in the top bar)
| Returns up to ~12 suggestions, each with a label and the page to open.
| Staff: students (name or Student ID) and scholarship programs.
| Students: open scholarships and their own applications.
| Page names ("payroll", "my documents") are matched in the browser.
*/
class SearchController extends Controller
{
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

        return response()->json(match ($request->user()->role) {
            'staff' => $this->forStaff($q, $like),
            'admin' => $this->forAdmin($like),
            default => $this->forStudent($request, $like),
        });
    }

    // Admin: accounts by name, e-mail or Student ID.
    private function forAdmin(string $like): array
    {
        $users = \App\Models\User::with('student:id,user_id,student_id')
            ->where(fn ($q) => $q->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhereHas('student', fn ($s) => $s->where('student_id', 'like', $like)))
            ->orderBy('name')
            ->limit(8)
            ->get();

        return $users->map(fn ($u) => [
            'kind' => $u->role === 'student' ? 'Student account' : ($u->role === 'admin' ? 'Admin account' : 'Staff account'),
            'label' => $u->name.($u->student ? " ({$u->student->student_id})" : ''),
            'detail' => $u->email.($u->is_active ? '' : ' · deactivated'),
            'to' => ($u->role === 'student' ? '/admin/students' : '/admin/staff').'?q='.urlencode($u->email),
        ])->all();
    }

    private function forStaff(string $q, string $like): array
    {
        $words = array_filter(preg_split('/\s+/', $q));

        $students = Student::query()
            ->where(function ($query) use ($words) {
                // Every word must match the first, middle or last name, or the Student ID.
                foreach ($words as $word) {
                    $w = '%'.str_replace(['%', '_'], ['\%', '\_'], $word).'%';
                    $query->where(fn ($q) => $q->where('first_name', 'like', $w)
                        ->orWhere('middle_name', 'like', $w)
                        ->orWhere('last_name', 'like', $w)
                        ->orWhere('student_id', 'like', $w));
                }
            })
            ->orderBy('last_name')
            ->limit(6)
            ->get();

        $scholarships = Scholarship::where('name', 'like', $like)
            ->orWhere('provider', 'like', $like)
            ->orderBy('name')
            ->limit(5)
            ->get();

        return [
            ...$students->map(fn ($s) => [
                'kind' => 'Student',
                'label' => "{$s->first_name} {$s->last_name} ({$s->student_id})",
                'detail' => $s->course,
                'to' => "/staff/data-bank?student={$s->id}",
            ]),
            ...$scholarships->map(fn ($s) => [
                'kind' => 'Scholarship',
                'label' => $s->name,
                'detail' => $s->provider,
                'to' => "/staff/scholarships?manage={$s->id}",
            ]),
        ];
    }

    private function forStudent(Request $request, string $like): array
    {
        $student = $request->user()->student;

        $scholarships = Scholarship::visibleToStudents()
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('provider', 'like', $like))
            ->orderBy('name')
            ->limit(6)
            ->get();

        $applications = $student
            ? Application::with('scholarship:id,name')
                ->where('student_id', $student->id)
                ->whereHas('scholarship', fn ($q) => $q->where('name', 'like', $like))
                ->limit(5)
                ->get()
            : collect();

        return [
            ...$applications->map(fn ($a) => [
                'kind' => 'My application',
                'label' => $a->scholarship->name,
                'detail' => str_replace('_', ' ', $a->status),
                'to' => "/student/applications/{$a->id}",
            ]),
            ...$scholarships->map(fn ($s) => [
                'kind' => 'Scholarship',
                'label' => $s->name,
                'detail' => $s->provider,
                'to' => "/student/scholarships/{$s->id}",
            ]),
        ];
    }
}
