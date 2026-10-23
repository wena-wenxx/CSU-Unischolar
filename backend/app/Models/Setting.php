<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/*
| Key/value system settings, changed by the admin in System Settings.
| When a key has never been saved, the default below is used.
*/
class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    public const SEMESTERS = ['1st Semester', '2nd Semester', 'Summer'];

    // Office details as published by the OAS (CSU website). Office hours were
    // not published there; confirm them with the OAS.
    public const DEFAULTS = [
        'current_school_year' => null,        // e.g. 2026-2027 (null = worked out from today's date)
        'current_semester' => null,           // 1st Semester / 2nd Semester / Summer
        'oas_office_hours' => 'Monday to Friday, 8:00 AM to 5:00 PM',
        'oas_location' => 'Caraga State University, Ampayon, Butuan City, Philippines',
        'oas_email' => 'oas@carsu.edu.ph',
        'oas_phone' => '0960 835 4606',
        'oas_head' => 'Prof. Sheila Rae E. Permanes, Unit Head',
        'oas_facebook' => 'https://www.facebook.com/carsuoas',
        'oas_about' => 'The Office of Admission and Scholarship is responsible for the development and implementation of programs and initiatives that are aimed at attracting, admitting, and supporting a diverse and talented student body while facilitating access to scholarship opportunities and financial aid resources to promote affordability and student success.',

        // Signatories printed on the General Payroll (General Form No. 6-A).
        // Titles follow the OAS form; NAMES are typed by the admin in System
        // Settings (never hard-coded), so they can change with the officials.
        'payroll_coordinator_name' => null,
        'payroll_coordinator_title' => 'Scholarship Coordinator',
        'payroll_director_name' => null,
        'payroll_director_title' => 'OAS Director',
        'payroll_registrar_name' => null,
        'payroll_registrar_title' => 'University Registrar',
        'payroll_vp_name' => null,
        'payroll_vp_title' => 'VP for Student Affairs and Auxiliary Services',
        'payroll_accountant_name' => null,
        'payroll_accountant_title' => 'Chief Accountant',
        'payroll_president_name' => null,
        'payroll_president_title' => 'University President',
        'payroll_cashier_name' => null,
        'payroll_cashier_title' => 'University Cashier',
    ];

    // The signatory slots on Form 6-A, in the order the form prints them.
    public const PAYROLL_SIGNATORIES = [
        'coordinator' => 'Noted by (1)',
        'director' => 'Noted by (2)',
        'registrar' => 'As to correctness of enrollment data',
        'vp' => 'Vice President',
        'accountant' => 'Funds available',
        'president' => 'Approved',
        'cashier' => 'Cashier',
    ];

    public const LABELS = [
        'current_school_year' => 'School year',
        'current_semester' => 'Semester',
        'oas_office_hours' => 'OAS office hours',
        'oas_location' => 'OAS location',
        'oas_email' => 'OAS e-mail',
        'oas_phone' => 'OAS phone',
        'oas_head' => 'OAS head',
        'oas_facebook' => 'OAS Facebook page',
        'oas_about' => 'About the OAS',
    ] + self::SIGNATORY_LABELS;

    private const SIGNATORY_LABELS = [
        'payroll_coordinator_name' => 'Payroll form: coordinator name', 'payroll_coordinator_title' => 'Payroll form: coordinator title',
        'payroll_director_name' => 'Payroll form: OAS director name', 'payroll_director_title' => 'Payroll form: OAS director title',
        'payroll_registrar_name' => 'Payroll form: registrar name', 'payroll_registrar_title' => 'Payroll form: registrar title',
        'payroll_vp_name' => 'Payroll form: VP name', 'payroll_vp_title' => 'Payroll form: VP title',
        'payroll_accountant_name' => 'Payroll form: accountant name', 'payroll_accountant_title' => 'Payroll form: accountant title',
        'payroll_president_name' => 'Payroll form: president name', 'payroll_president_title' => 'Payroll form: president title',
        'payroll_cashier_name' => 'Payroll form: cashier name', 'payroll_cashier_title' => 'Payroll form: cashier title',
    ];

    /** [slot => ['name' => ..., 'title' => ...]] for the payroll form. */
    public static function payrollSignatories(): array
    {
        $values = self::allValues();
        $out = [];
        foreach (array_keys(self::PAYROLL_SIGNATORIES) as $slot) {
            $out[$slot] = [
                'name' => $values["payroll_{$slot}_name"] ?? null,
                'title' => $values["payroll_{$slot}_title"] ?? null,
            ];
        }

        return $out;
    }

    public static function get(string $key): ?string
    {
        $value = self::query()->whereKey($key)->value('value');

        return $value !== null && $value !== '' ? $value : (self::DEFAULTS[$key] ?? null);
    }

    public static function allValues(): array
    {
        $saved = self::query()->pluck('value', 'key')->all();

        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = isset($saved[$key]) && $saved[$key] !== '' ? $saved[$key] : $default;
        }

        return $out;
    }

    public static function put(string $key, ?string $value, ?int $userId = null): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
    }
}
