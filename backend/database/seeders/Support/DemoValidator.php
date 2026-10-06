<?php

namespace Database\Seeders\Support;

/**
 * PHP copy of the rules in ai-service/validation.py, used ONLY to pre-fill
 * demo AI results while seeding. Because the same rules run on the same
 * text, pressing "Re-run AI check" on a seeded document gives the same
 * result. If you change validation.py, change this file the same way.
 *
 * The AI only FLAGS possible problems; staff always decide.
 */
class DemoValidator
{
    private const NAME_MATCH_MIN = 95;
    private const NAME_SIMILAR_MIN = 75;
    private const MIN_TEXT_CHARS = 30;

    // kind, label pattern, keywords, shows student ID?, name mode
    private const DOC_KINDS = [
        ['registration', '/registration|\bcor\b|enrol/', ['REGISTRATION', 'ENROLL', 'UNITS', 'SUBJECT', 'SEMESTER'], true, 'full'],
        ['grades', '/grade|report card|transcript|\btor\b/', ['GRADE', 'GRADES', 'GWA', 'AVERAGE', 'SEMESTER', 'SUBJECT', 'UNITS', 'REPORT'], true, 'full'],
        ['id', '/\bid\b|identification/', ['IDENTIFICATION', 'VALID', 'ID NO', 'ID NUMBER', 'SIGNATURE', 'STUDENT'], true, 'full'],
        ['birth_certificate', '/birth/', ['BIRTH', 'LIVE BIRTH', 'PSA', 'REGISTRY', 'CIVIL REGISTRAR'], false, 'full'],
        ['indigency', '/indigen/', ['INDIGENCY', 'INDIGENT', 'LOW INCOME'], false, 'full'],
        ['barangay_clearance', '/clearance/', ['CLEARANCE'], false, 'full'],
        ['good_moral', '/moral/', ['GOOD MORAL', 'MORAL CHARACTER'], false, 'full'],
        ['income_tax_return', '/income tax|\bitr\b|tax return/', ['INCOME TAX', 'TAX RETURN', 'TAXABLE INCOME', 'INTERNAL REVENUE', 'TAX DUE'], false, 'surname'],
        ['recommendation_letter', '/recommend/', ['RECOMMEND'], false, 'full'],
    ];

    public static function normalize(string $text): string
    {
        $text = mb_strtoupper($text, 'UTF-8');
        $text = preg_replace('/[^A-Z0-9\s]/u', ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** rapidfuzz fuzz.ratio: 2 * LCS / (len_a + len_b) * 100 */
    private static function ratio(string $a, string $b): float
    {
        $la = strlen($a);
        $lb = strlen($b);

        if ($la + $lb === 0) {
            return 100.0;
        }

        $prev = array_fill(0, $lb + 1, 0);

        for ($i = 1; $i <= $la; $i++) {
            $cur = [0];
            for ($j = 1; $j <= $lb; $j++) {
                $cur[$j] = $a[$i - 1] === $b[$j - 1]
                    ? $prev[$j - 1] + 1
                    : max($prev[$j], $cur[$j - 1]);
            }
            $prev = $cur;
        }

        return 200.0 * $prev[$lb] / ($la + $lb);
    }

    /** @var array<string, float> remembered comparisons (speeds up seeding) */
    private static array $cache = [];

    private static function tokenSortRatio(string $a, string $b): float
    {
        $key = $a . '|' . $b;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        if (count(self::$cache) > 200000) {
            self::$cache = [];
        }

        $sa = preg_split('/\s+/', trim($a), -1, PREG_SPLIT_NO_EMPTY);
        $sb = preg_split('/\s+/', trim($b), -1, PREG_SPLIT_NO_EMPTY);
        sort($sa);
        sort($sb);

        return self::$cache[$key] = self::ratio(implode(' ', $sa), implode(' ', $sb));
    }

    private static function combinations(array $items, int $k): array
    {
        if ($k === 0) {
            return [[]];
        }
        if (count($items) < $k) {
            return [];
        }

        $first = $items[0];
        $rest = array_slice($items, 1);
        $with = array_map(fn ($c) => array_merge([$first], $c), self::combinations($rest, $k - 1));

        return array_merge($with, self::combinations($rest, $k));
    }

    /** Same as best_name_match() in validation.py. Returns [score, window]. */
    public static function bestNameMatch(string $expectedName, string $text): array
    {
        $expected = self::normalize($expectedName);
        $words = preg_split('/\s+/', self::normalize($text), -1, PREG_SPLIT_NO_EMPTY);

        if ($expected === '' || !$words) {
            return [0.0, ''];
        }

        $k = count(explode(' ', $expected));
        $sizes = array_unique([max(1, $k - 1), $k, $k + 1, $k + 2]);
        sort($sizes);

        $bestScore = 0.0;
        $bestWindow = '';

        foreach ($sizes as $size) {
            if ($size > count($words)) {
                continue;
            }
            for ($i = 0; $i <= count($words) - $size; $i++) {
                $window = array_slice($words, $i, $size);
                $candidates = $size > $k ? self::combinations($window, $k) : [$window];

                foreach ($candidates as $candidate) {
                    $score = self::tokenSortRatio($expected, implode(' ', $candidate));
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestWindow = implode(' ', $window);

                        // Python only replaces the best on a HIGHER score, and
                        // nothing beats 100, so stopping here gives the same result.
                        if ($bestScore >= 100.0) {
                            return [round($bestScore, 2), $bestWindow];
                        }
                    }
                }
            }
        }

        return [round($bestScore, 2), $bestWindow];
    }

    /** Write a score the way Python prints a float: 90.0, 86.67, 47.1 */
    private static function pyFloat(float $value): string
    {
        $text = rtrim(rtrim(sprintf('%.2f', $value), '0'), '.');

        return str_contains($text, '.') ? $text : $text . '.0';
    }

    private static function kindFor(string $label): ?array
    {
        $label = strtolower($label);

        foreach (self::DOC_KINDS as $kind) {
            if (preg_match($kind[1], $label)) {
                return $kind;
            }
        }

        return null;
    }

    /** Same output as validate_text() in validation.py. */
    public static function validate(string $text, string $expectedName, string $studentId, string $label, string $lastName): array
    {
        $flags = [];
        $readable = mb_strlen(trim($text)) >= self::MIN_TEXT_CHARS;
        $hasMissing = false;
        $hasMismatch = false;
        $hasWrong = false;

        $kindRow = self::kindFor($label);
        [$kind, , $keywords, $showsId, $nameMode] = $kindRow ?? [null, null, [], false, 'full'];

        if (!$readable) {
            $hasMissing = true;
            $flags[] = 'Very little text could be read from this file (blank, unclear or low quality scan).';
        }

        $surnameOnly = $nameMode === 'surname';
        $target = $surnameOnly ? $lastName : $expectedName;
        $score = null;
        $window = '';

        if (trim($target) !== '' && $readable) {
            [$score, $window] = self::bestNameMatch($target, $text);

            if ($score >= self::NAME_MATCH_MIN) {
                // matches
            } elseif ($surnameOnly) {
                $hasMismatch = true;
                $flags[] = "Applicant's surname (" . trim($target) . ") was not found on this parent's document (best match " . self::pyFloat($score) . "%).";
            } elseif ($score >= self::NAME_SIMILAR_MIN) {
                $hasMismatch = true;
                $flags[] = "Name is similar but not exact (match " . self::pyFloat($score) . "%). Found: '{$window}'. Please check.";
            } else {
                $hasMismatch = true;
                $flags[] = "Applicant name not found on the document (best match " . self::pyFloat($score) . "%).";
            }
        }

        $idFound = null;
        if (trim($studentId) !== '' && $readable) {
            $a = preg_replace('/[^A-Z0-9]/', '', strtoupper($studentId));
            $b = preg_replace('/[^A-Z0-9]/', '', strtoupper($text));
            $idFound = $a !== '' && str_contains($b, $a);

            if (!$idFound && $showsId) {
                $hasMissing = true;
                $flags[] = 'Student ID number was not found on the document.';
            }
        }

        $hits = [];
        if ($kind !== null && $readable) {
            $norm = self::normalize($text);
            $hits = array_values(array_filter($keywords, fn ($w) => str_contains($norm, $w)));

            if (!$hits) {
                $hasWrong = true;
                $flags[] = "This does not look like the requested document ({$label}).";
            }
        }

        return [
            'is_complete' => !$hasMissing && !$hasWrong,
            'has_name_mismatch' => $hasMismatch,
            'has_missing_information' => $hasMissing,
            'has_wrong_document' => $hasWrong,
            'confidence_score' => $score,
            'flags' => $flags,
            'extracted_data' => [
                'name_score' => $score,
                'best_name_window' => $window,
                'student_id_found' => $idFound,
                'document_kind' => $kind,
                'name_checked' => $surnameOnly ? 'surname' : 'full name',
                'keywords_found' => $hits,
                'text_length' => mb_strlen(trim($text)),
                'document_label' => $label,
                'detected_name' => $window !== '' ? $window : null,
            ],
        ];
    }
}
