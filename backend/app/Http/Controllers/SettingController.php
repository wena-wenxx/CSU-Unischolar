<?php

namespace App\Http\Controllers;

use App\Models\Setting;

class SettingController extends Controller
{
    // GET /settings/public: OAS contact details and the current term,
    // for the Contact OAS page and the page footer (any logged-in user).
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
        ]);
    }

    /*
    | GET /office-contact  (no login needed)
    | Only the OAS public contact details, for the login page
    | ("Forgot your password?" / "Need help?").
    */
    public function officeContact()
    {
        $values = Setting::allValues();

        return response()->json([
            'oas_office_hours' => $values['oas_office_hours'],
            'oas_location' => $values['oas_location'],
            'oas_email' => $values['oas_email'],
            'oas_phone' => $values['oas_phone'],
            // Demo accounts for testing; only when DEMO_LOGIN_ACCOUNTS=true in .env.
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
