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
    ];

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
