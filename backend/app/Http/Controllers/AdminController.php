<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Scholarship;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/*
|--------------------------------------------------------------------------
| System administrator
|--------------------------------------------------------------------------
| The admin manages ACCOUNTS (staff, admins, students), reads the ACTIVITY
| LOG and changes SYSTEM SETTINGS. The admin does not review applications,
| tag grantees or prepare payroll: that stays with OAS staff, so every
| decision in the records was made by the office that owns it.
| Accounts are never deleted, only deactivated, so their history is kept.
*/
class AdminController extends Controller
{
    private function adminOnly(Request $request): User
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            abort(response()->json(['message' => 'Only the system administrator can do this.'], 403));
        }

        return $user;
    }

    // GET /admin/dashboard
    public function dashboard(Request $request)
    {
        $this->adminOnly($request);

        $since = now()->subDays(7);

        return response()->json([
            'accounts' => [
                'staff' => User::where('role', 'staff')->where('is_active', true)->count(),
                'admins' => User::where('role', 'admin')->where('is_active', true)->count(),
                'students' => User::where('role', 'student')->where('is_active', true)->count(),
                'inactive' => User::where('is_active', false)->count(),
                'must_change_password' => User::where('must_change_password', true)->count(),
            ],
            'logins_today' => ActivityLog::where('action', 'auth.login')->where('created_at', '>=', now()->startOfDay())->count(),
            'actions_7_days' => ActivityLog::where('created_at', '>=', $since)->count(),
            'scholarships' => Scholarship::count(),
            'applications' => Application::where('status', '!=', 'draft')->count(),
            'term' => PayrollController::currentPeriod(),
            'settings' => Setting::allValues(),
            'recent' => ActivityLog::latest('created_at')->latest('id')->limit(10)->get(),
        ]);
    }

    /*
    | GET /admin/users?role=staff|admin|office|student&q=&status=active|inactive&page=
    | role=office lists staff and admins together (Manage Staff).
    */
    public function users(Request $request)
    {
        $this->adminOnly($request);

        $filters = $request->validate([
            'role' => 'nullable|in:student,staff,admin,office',
            'q' => 'nullable|string|max:200',
            'status' => 'nullable|in:active,inactive',
            'page' => 'nullable|integer|min:1',
        ]);

        $words = array_filter(preg_split('/\s+/', trim((string) ($filters['q'] ?? ''))));
        $perPage = 25;
        $page = (int) ($filters['page'] ?? 1);

        $query = User::query()
            ->with('student')
            ->when($filters['role'] ?? null, fn ($q, $role) => $role === 'office'
                ? $q->whereIn('role', ['staff', 'admin'])
                : $q->where('role', $role))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($words, function ($query) use ($words) {
                foreach ($words as $word) {
                    $like = '%'.$word.'%';
                    $query->where(fn ($w) => $w->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhereHas('student', fn ($s) => $s->where('student_id', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('first_name', 'like', $like)));
                }
            });

        $total = (clone $query)->count();

        $users = $query
            ->leftJoin('students', 'students.user_id', '=', 'users.id')
            ->orderByRaw('coalesce(students.last_name, users.name)')
            ->orderBy('users.name')
            ->select('users.*')
            ->skip(($page - 1) * $perPage)->take($perPage)
            ->get();

        return response()->json([
            'data' => $users,
            'total' => $total,
            'page' => $page,
            'has_more' => $page * $perPage < $total,
        ]);
    }

    private function temporaryPassword(): string
    {
        // Easy to read out loud: no 0/O or 1/I/L.
        $letters = 'ABCDEFGHJKMNPQRSTUVWXYZ';
        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= $letters[random_int(0, strlen($letters) - 1)];
        }

        return 'Csu-'.$code.'-'.random_int(2000, 9999);
    }

    /*
    | POST /admin/users
    | Staff/admin: name, email, role.
    | Student: first_name, middle_name, last_name, sex, student_id, course,
    |          year_level, college, contact_number, email.
    | password is optional: when empty a temporary one is made and shown once.
    | The new user must change it at first login.
    */
    public function store(Request $request)
    {
        $admin = $this->adminOnly($request);

        $data = $request->validate([
            'role' => 'required|in:student,staff,admin',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'nullable|string|min:8|max:100',
            'name' => 'required_unless:role,student|nullable|string|max:255',
            'first_name' => 'required_if:role,student|nullable|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required_if:role,student|nullable|string|max:255',
            'sex' => 'nullable|in:Female,Male',
            'student_id' => 'required_if:role,student|nullable|string|max:50|unique:students,student_id',
            'course' => 'nullable|string|max:255',
            'year_level' => 'nullable|string|max:255',
            'college' => 'nullable|string|max:255',
            'contact_number' => ['nullable', 'regex:/^09\d{9}$/'],
        ], [
            'contact_number.regex' => 'Enter an 11-digit mobile number that starts with 09.',
        ]);

        $password = $data['password'] ?? null;
        $generated = $password === null;
        $password ??= $this->temporaryPassword();

        $user = DB::transaction(function () use ($data, $password) {
            $isStudent = $data['role'] === 'student';

            $user = User::create([
                'name' => $isStudent ? trim($data['first_name'].' '.$data['last_name']) : trim($data['name']),
                'email' => strtolower(trim($data['email'])),
                'password' => Hash::make($password),
                'role' => $data['role'],
                'is_active' => true,
                'must_change_password' => true,
            ]);

            if ($isStudent) {
                Student::create([
                    'user_id' => $user->id,
                    'student_id' => trim($data['student_id']),
                    'first_name' => trim($data['first_name']),
                    'middle_name' => $data['middle_name'] ?? null,
                    'last_name' => trim($data['last_name']),
                    'sex' => $data['sex'] ?? null,
                    'course' => $data['course'] ?? null,
                    'year_level' => $data['year_level'] ?? null,
                    'college' => $data['college'] ?? null,
                    'contact_number' => $data['contact_number'] ?? null,
                ]);
            }

            return $user;
        });

        ActivityLog::record($admin, 'account.created', "Created {$user->role} account {$user->name} ({$user->email}).", $user);

        return response()->json([
            'message' => 'Account created. Give the user the temporary password; they must change it when they first log in.',
            'user' => $user->load('student'),
            'temporary_password' => $password,
            'generated' => $generated,
        ], 201);
    }

    // PATCH /admin/users/{id}: name, e-mail, role (staff <-> admin), student details.
    public function update(Request $request, $id)
    {
        $admin = $this->adminOnly($request);
        $user = User::with('student')->findOrFail($id);

        $data = $request->validate([
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'name' => 'sometimes|required|string|max:255',
            'role' => 'sometimes|in:staff,admin',
            'first_name' => 'sometimes|required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'sex' => 'nullable|in:Female,Male',
            'student_id' => ['sometimes', 'required', 'string', 'max:50',
                Rule::unique('students', 'student_id')->ignore($user->student?->id)],
            'course' => 'nullable|string|max:255',
            'year_level' => 'nullable|string|max:255',
            'college' => 'nullable|string|max:255',
            'contact_number' => ['nullable', 'regex:/^09\d{9}$/'],
        ]);

        if (isset($data['role'])) {
            if ($user->role === 'student') {
                return response()->json(['message' => 'A student account cannot be changed into a staff account.'], 422);
            }
            if ($user->id === $admin->id && $data['role'] !== 'admin') {
                return response()->json(['message' => 'You cannot remove your own administrator role.'], 422);
            }
            if ($user->isAdmin() && $data['role'] !== 'admin' && $this->activeAdmins() <= 1) {
                return response()->json(['message' => 'This is the only active administrator. Make another account an administrator first.'], 422);
            }
        }

        $changes = [];

        DB::transaction(function () use ($user, $data, &$changes) {
            $userFields = array_intersect_key($data, array_flip(['email', 'name', 'role']));
            if (isset($userFields['email'])) {
                $userFields['email'] = strtolower(trim($userFields['email']));
            }

            if ($user->student) {
                $studentFields = array_intersect_key($data, array_flip([
                    'first_name', 'middle_name', 'last_name', 'sex', 'student_id', 'course', 'year_level', 'college', 'contact_number',
                ]));
                $user->student->fill($studentFields);
                $changes = array_keys($user->student->getDirty());
                $user->student->save();

                $userFields['name'] = trim($user->student->first_name.' '.$user->student->last_name);
                unset($userFields['role']);
            }

            $user->fill($userFields);
            $changes = array_merge($changes, array_keys($user->getDirty()));
            $user->save();
        });

        if ($changes) {
            ActivityLog::record($admin, 'account.updated', "Updated {$user->name}: ".implode(', ', array_unique($changes)).'.', $user);
        }

        return response()->json(['message' => 'Account saved.', 'user' => $user->fresh()->load('student')]);
    }

    private function activeAdmins(): int
    {
        return User::where('role', 'admin')->where('is_active', true)->count();
    }

    // POST /admin/users/{id}/reset-password   Body: password (optional)
    public function resetPassword(Request $request, $id)
    {
        $admin = $this->adminOnly($request);
        $user = User::findOrFail($id);

        $data = $request->validate(['password' => 'nullable|string|min:8|max:100']);
        $password = $data['password'] ?? $this->temporaryPassword();

        $user->forceFill(['password' => Hash::make($password), 'must_change_password' => true])->save();
        $user->tokens()->delete(); // signs the user out everywhere

        ActivityLog::record($admin, 'account.password_reset', "Reset the password of {$user->name} ({$user->email}).", $user);

        return response()->json([
            'message' => 'Password reset. The user must change it when they next log in.',
            'temporary_password' => $password,
        ]);
    }

    // POST /admin/users/{id}/active   Body: active (true/false)
    public function setActive(Request $request, $id)
    {
        $admin = $this->adminOnly($request);
        $user = User::findOrFail($id);
        $active = $request->validate(['active' => 'required|boolean'])['active'];

        if (!$active && $user->id === $admin->id) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        if (!$active && $user->isAdmin() && $this->activeAdmins() <= 1) {
            return response()->json(['message' => 'This is the only active administrator and cannot be deactivated.'], 422);
        }

        $user->forceFill(['is_active' => (bool) $active])->save();

        if (!$active) {
            $user->tokens()->delete();
        }

        ActivityLog::record($admin, $active ? 'account.reactivated' : 'account.deactivated',
            ($active ? 'Reactivated ' : 'Deactivated ')."{$user->name} ({$user->email}).", $user);

        return response()->json([
            'message' => $active ? 'Account reactivated.' : 'Account deactivated. The user is signed out and cannot log in.',
            'user' => $user->fresh()->load('student'),
        ]);
    }

    /*
    | GET /admin/activity?q=&role=&action=&from=&to=&page=&export=1
    | action may be a group: "auth", "account", "application", "payroll" ...
    */
    public function activity(Request $request)
    {
        $this->adminOnly($request);

        $filters = $request->validate([
            'q' => 'nullable|string|max:200',
            'role' => 'nullable|in:student,staff,admin',
            'action' => 'nullable|string|max:60',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
            'export' => 'nullable|boolean',
        ]);

        $query = ActivityLog::query()
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', 'like', $action.'%'))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', \Carbon\Carbon::parse($from, 'Asia/Manila')->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', \Carbon\Carbon::parse($to, 'Asia/Manila')->endOfDay()->utc()))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(fn ($w) => $w
                ->where('description', 'like', '%'.$text.'%')
                ->orWhere('user_name', 'like', '%'.$text.'%')));

        $total = (clone $query)->count();
        $export = (bool) ($filters['export'] ?? false);
        $perPage = $export ? 5000 : 50;
        $page = $export ? 1 : (int) ($filters['page'] ?? 1);

        $rows = $query->latest('created_at')->latest('id')
            ->skip(($page - 1) * $perPage)->take($perPage)->get();

        return response()->json([
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'has_more' => !$export && $page * $perPage < $total,
            'actions' => ActivityLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    // GET /admin/settings
    public function settings(Request $request)
    {
        $this->adminOnly($request);

        return response()->json([
            'values' => Setting::allValues(),
            'labels' => Setting::LABELS,
            'semesters' => Setting::SEMESTERS,
            'current_period' => PayrollController::currentPeriod(),
        ]);
    }

    // PUT /admin/settings
    public function updateSettings(Request $request)
    {
        $admin = $this->adminOnly($request);

        $data = $request->validate([
            'current_school_year' => ['nullable', 'regex:/^\d{4}-\d{4}$/'],
            'current_semester' => 'nullable|in:'.implode(',', Setting::SEMESTERS),
            'oas_office_hours' => 'nullable|string|max:255',
            'oas_location' => 'nullable|string|max:255',
            'oas_email' => 'nullable|email|max:255',
            'oas_phone' => 'nullable|string|max:60',
        ], [
            'current_school_year.regex' => 'Write the school year like 2026-2027.',
        ]);

        if (!empty($data['current_school_year'])) {
            [$a, $b] = array_map('intval', explode('-', $data['current_school_year']));
            if ($b !== $a + 1) {
                return response()->json(['message' => 'The school year must be two years in a row, like 2026-2027.'], 422);
            }
        }

        $before = Setting::allValues();
        $changed = [];

        foreach ($data as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;
            if (($before[$key] ?? null) !== ($value === '' ? null : $value)) {
                Setting::put($key, $value === '' ? null : $value, $admin->id);
                $changed[] = Setting::LABELS[$key].': '.($value ?: '(empty)');
            }
        }

        if ($changed) {
            ActivityLog::record($admin, 'settings.updated', 'Changed settings: '.implode('; ', $changed).'.');
        }

        return response()->json([
            'message' => $changed ? 'Settings saved.' : 'Nothing changed.',
            'values' => Setting::allValues(),
            'current_period' => PayrollController::currentPeriod(),
        ]);
    }
}
