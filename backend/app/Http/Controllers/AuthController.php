<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'first_name'     => 'required|string|max:255',
            'middle_name'    => 'nullable|string|max:255',
            'last_name'      => 'required|string|max:255',
            'sex'            => 'nullable|in:Female,Male',
            'email'          => 'required|email|max:255|unique:users,email',
            'password'       => 'required|string|min:8',
            'student_id'     => 'required|string|unique:students,student_id',
            'course'         => 'nullable|string|max:255',
            'year_level'     => 'nullable|string|max:255',
            'college'        => 'nullable|string|max:255',
            'contact_number' => 'nullable|string|max:255',
        ]);

        $user = DB::transaction(function () use ($request) {

            $user = User::create([
                'name' => trim(
                    $request->first_name . ' ' . $request->last_name
                ),
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'student',
            ]);

            Student::create([
                'user_id' => $user->id,
                'student_id' => $request->student_id,
                'first_name' => $request->first_name,
                'middle_name' => $request->middle_name,
                'last_name' => $request->last_name,
                'sex' => $request->sex,
                'course' => $request->course,
                'year_level' => $request->year_level,
                'college' => $request->college,
                'contact_number' => $request->contact_number,
            ]);

            return $user;
        });

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful',
            'user' => $user->load('student'),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::with('student')
            ->where('email', $request->email)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        // A deactivated account keeps its history but cannot log in.
        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account is deactivated. Please contact the OAS.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        ActivityLog::record($user, 'auth.login', "{$user->name} logged in.");

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(
            $request->user()->load('student')
        );
    }

    /*
    | POST /change-password   Body: current_password, password, password_confirmation
    | Every user can change their own password. Required after an admin
    | gave the account a temporary password (must_change_password).
    */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
        ], [
            'password.regex' => 'The new password needs at least one letter and one number.',
        ]);

        if (!Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Your current password is not correct.'],
            ]);
        }

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Choose a password different from the current one.'],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
        ])->save();

        // Sign out every other device; keep this session.
        $current = $user->currentAccessToken()?->id;
        $user->tokens()->when($current, fn ($q) => $q->where('id', '!=', $current))->delete();

        ActivityLog::record($user, 'auth.password_changed', "{$user->name} changed their password.");

        return response()->json([
            'message' => 'Password changed.',
            'user' => $user->fresh()->load('student'),
        ]);
    }

    public function logout(Request $request)
    {
        ActivityLog::record($request->user(), 'auth.logout', "{$request->user()->name} logged out.");
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }
}