<?php

namespace App\Http\Controllers;

use App\Models\Setting;

class SettingController extends Controller
{
    public function publicSettings()
    {
        $values = Setting::allValues();

        return response()->json([
            'oas_office_hours' => $values['oas_office_hours'],
            'oas_location' => $values['oas_location'],
            'oas_email' => $values['oas_email'],
            'oas_phone' => $values['oas_phone'],
            'oas_head' => $values['oas_head'],
            'oas_facebook' => $values['oas_facebook'],
            'oas_about' => $values['oas_about'],
            'current_period' => PayrollController::currentPeriod(),
            'current_school_year' => \App\Models\ExternalGrantee::currentSchoolYear(),
            'current_semester' => $values['current_semester'] ?: preg_replace('/ AY .*$/', '', PayrollController::currentPeriod()),
            'semesters' => Setting::SEMESTERS,
        ]);
    }

    public function officeContact()
    {
        $values = Setting::allValues();

        return response()->json([
            'oas_office_hours' => $values['oas_office_hours'],
            'oas_location' => $values['oas_location'],
            'oas_email' => $values['oas_email'],
            'oas_phone' => $values['oas_phone'],
            'demo_accounts' => config('demo.show_on_login') ? [
                ['role' => 'OAS staff', 'email' => 'oas.staff@carsu.edu.ph', 'password' => 'Staff@12345'],
                ['role' => 'Admin', 'email' => 'admin@carsu.edu.ph', 'password' => 'Admin@12345'],
                ['role' => 'Student: '.config('demo.test_student.first_name').' '.config('demo.test_student.last_name'),
                    'email' => config('demo.test_student.email'), 'password' => 'Student@12345'],
                ['role' => 'Student: Juan Navarro (fictional)', 'email' => 'student1@carsu.edu.ph', 'password' => 'Student@12345'],
            ] : [],
        ]);
    }
}
