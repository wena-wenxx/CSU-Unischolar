<?php

namespace App\Support;

use Illuminate\Support\Carbon;

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
