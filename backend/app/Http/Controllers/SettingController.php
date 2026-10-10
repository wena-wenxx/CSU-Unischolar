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
}
