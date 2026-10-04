<?php

namespace App\Http\Controllers;

use App\Models\ScholarRecord;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | STUDENT - MY PROFILE
    |--------------------------------------------------------------------------
    | Returns the logged-in user with their student profile attached.
    | The frontend reads profile.email and profile.student.*
    */

    public function profile(Request $request)
    {
        $user = $request->user()->load('student');

        if ($user->role === 'student' && !$user->student) {
            return response()->json([
                'message' => 'Student profile not found.'
            ], 404);
        }

        return response()->json($user);
    }


    /*
    |--------------------------------------------------------------------------
    | STUDENT - MY SCHOLARSHIP HISTORY
    |--------------------------------------------------------------------------
    | Every scholarship this student has been tagged as a grantee for,
    | newest first, with the payroll entries prepared for each one.
    */

    public function history(Request $request)
    {
        if ($request->user()->role !== 'student') {
            return response()->json([
                'message' => 'Only students have a scholarship history.'
            ], 403);
        }

        $student = $request->user()->student;

        if (!$student) {
            return response()->json([
                'message' => 'Student profile not found.'
            ], 404);
        }

        $records = ScholarRecord::with([
            'scholarship',
            'payrollRecords'
        ])
            ->where('student_id', $student->id)
            ->latest()
            ->get();

        return response()->json($records);
    }
}
