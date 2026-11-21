<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/*
| How old is a certificate? The AI service sends the dates it read on the document
| ("this 7th day of July, 2022", "July 7, 2022", "7/7/2022"). The newest one is taken as
| the issue date (a birth date or an older date on the same page is always earlier).
| Only certificates that must be recent are checked; COR and grades use the school-year check.
| The result is a flag for staff, never a rejection: OCR can misread a date.
*/
class DocumentAge
{
    public const CHECKED_TYPES = [
        'Certificate of Indigency',
        'Barangay Clearance',
        'Certificate of Good Moral Character',
        'Recommendation Letter',
        "Parents' Income Tax Return",
    ];

    public static function newestDate(array $texts): ?Carbon
    {
        $dates = [];
        foreach ($texts as $text) {
            $text = trim((string) $text);
            // "7th day of July, 2022" -> "July 7 2022"
            if (preg_match('/^(\d{1,2})(?:st|nd|rd|th|[^\s\d]{1,2})?\s+day\s+of\s+([A-Za-z]+),?\s+(\d{4})$/i', $text, $m)) {
                $text = "{$m[2]} {$m[1]} {$m[3]}";
            }
            try {
                $date = Carbon::parse(str_replace(',', '', $text));
            } catch (\Throwable) {
                continue;
            }
            if ($date->year >= 1950 && $date->lte(now()->addDay())) {
                $dates[] = $date;
            }
        }

        return $dates ? collect($dates)->sortDesc()->first() : null;
    }

    /** A flag sentence when the certificate is older than the office allows, else null. */
    public static function outdatedFlag(string $type, array $texts): ?string
    {
        $months = \App\Models\Document::VALIDITY_MONTHS[$type] ?? null;
        if (!$months || !in_array($type, self::CHECKED_TYPES, true)) {
            return null;
        }

        $issued = self::newestDate($texts);
        if (!$issued || $issued->copy()->addMonths($months)->isFuture()) {
            return null;
        }

        return "This document seems to be dated {$issued->format('F j, Y')}, more than {$months} months ago. "
            .'Ask the student for a recent copy if the program needs one (check the date yourself: the AI can misread it).';
    }
}
