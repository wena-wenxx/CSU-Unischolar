<?php

namespace App\Support;

use App\Models\AiRecommendation;

/*
|--------------------------------------------------------------------------
| "What to do next" for an AI recommendation (round 12)
|--------------------------------------------------------------------------
| Plain rules on top of the eligibility checks; nothing is predicted.
|
| Important: the % counts checks met, so a LOW % with "Meets the rules"
| usually means documents are missing, not that the student is unlikely.
| That is why the advice lists the actual missing items.
|
| Score bands (same as the filter on AI Recommendations):
|   90-100  ready: invite or add
|   50-89   invite, naming the missing documents
|   0-49    qualifies, most documents missing: a reminder is optional
*/
class NextSteps
{
    private const DOC_PREFIX = 'Required document: ';

    /** Advice for OAS staff (shown in the Why? window). */
    public static function forStaff(AiRecommendation $r): array
    {
        $checks = $r->checks ?? [];
        $failed = array_values(array_filter($checks, fn ($c) => $c['must'] && $c['result'] === 'not_met'));
        $unknown = array_values(array_filter($checks, fn ($c) => $c['must'] && $c['result'] === 'no_data'));
        $docs = self::documentGaps($checks);
        $steps = [];

        if ($r->applied_status && !in_array($r->applied_status, ['draft'], true)) {
            return [
                'tone' => 'neutral',
                'title' => 'The student already applied',
                'steps' => ['The application is '.str_replace('_', ' ', $r->applied_status).'. Follow it in Applications instead of inviting.'],
            ];
        }

        if ($r->verdict === 'not_eligible') {
            foreach ($failed as $c) {
                $steps[] = "Fails: {$c['rule']} (student's data: {$c['value']}).";
            }
            $steps[] = 'Do not invite. Press Dismiss. If the student\'s data is wrong, press Mark as wrong and ask them to correct it.';

            return ['tone' => 'danger', 'title' => 'Not eligible for this program', 'steps' => $steps];
        }

        if ($r->verdict === 'no_data') {
            foreach ($unknown as $c) {
                $steps[] = in_array($c['key'], ['gwa', 'income'], true)
                    ? "Ask the student to enter their {$c['label']} on My Profile."
                    : "{$c['label']} is not recorded: correct the student's account (Data Bank or a profile correction request).";
            }
            $steps[] = 'Then press Recalculate. Until then the student cannot be judged for this program.';

            return ['tone' => 'warning', 'title' => 'Missing student data', 'steps' => $steps];
        }

        // Meets every must-have rule.
        if (!$r->data_verified) {
            $steps[] = 'First check the GWA against the Certificate of Grades and the income against the ITR, then press Mark GWA and income as checked.';
        }
        if ($docs['missing']) {
            $steps[] = 'Not uploaded or expired: '.implode(', ', $docs['missing']).'.';
        }
        if ($docs['problem']) {
            $steps[] = 'Uploaded, but the AI found a problem: '.implode(', ', $docs['problem']).'. Ask the student to replace it, or check it yourself.';
        }
        if ($docs['unchecked']) {
            $steps[] = 'Uploaded, not counted yet: '.implode(', ', $docs['unchecked']).'. Run the AI check, or verify it by hand (How to check it is genuine).';
        }

        if ($r->score >= 90) {
            $steps[] = 'Ready: press Send invitation, or Add to scholarship to start a draft application.';
            $title = 'Ready to invite';
            $tone = 'success';
        } elseif ($r->score >= 50) {
            $steps[] = 'Press Send invitation and name the missing documents in the note.';
            $title = 'Qualifies, a few documents left';
            $tone = 'success';
        } else {
            $steps[] = 'The low score is because of missing documents, not the rules. An invitation is optional; it is not a priority yet.';
            $title = 'Qualifies, most documents missing';
            $tone = 'warning';
        }

        return ['tone' => $tone, 'title' => $title, 'steps' => $steps];
    }

    /**
     * What the STUDENT still has to do for one program (no percentage shown).
     * Each item: ['text' => ..., 'kind' => 'profile' | 'upload' | 'renew' | 'check' | 'wait', 'doc' => name].
     */
    /**
     * $sentToOas: document names whose newest copy is in an application already sent to OAS.
     * The student cannot run the AI check on those (OAS checks them), so they become "wait" items.
     */
    public static function forStudent(AiRecommendation $r, array $sentToOas = []): array
    {
        $todo = [];

        foreach ($r->checks ?? [] as $c) {
            if ($c['result'] === 'met') {
                continue;
            }
            if ($c['must']) {
                if ($c['result'] !== 'no_data') {
                    continue;   // failed programs are not shown to students at all
                }
                $todo[] = match ($c['key']) {
                    'gwa' => ['text' => 'Enter your GWA on My Profile', 'kind' => 'profile'],
                    'income' => ['text' => "Enter your family's yearly income on My Profile", 'kind' => 'profile'],
                    default => ['text' => "Your {$c['label']} is not recorded: send a profile correction request on My Profile", 'kind' => 'profile'],
                };
                continue;
            }

            $name = self::documentName($c);
            $value = (string) $c['value'];
            $todo[] = ['doc' => $name] + match (true) {
                $value === 'Not uploaded' => ['text' => "Upload your {$name}", 'kind' => 'upload'],
                str_contains($value, 'expired') => ['text' => "Upload a new {$name} (yours has expired)", 'kind' => 'renew'],
                str_contains($value, 'AI flagged') => ['text' => "Replace your {$name}: the check found a problem (".self::after($value, 'AI flagged: ').')', 'kind' => 'renew'],
                str_contains($value, 'could not read') => ['text' => "{$name}: uploaded; the AI could not read it, so OAS will check it by hand", 'kind' => 'wait'],
                in_array($name, $sentToOas, true) => ['text' => "{$name}: sent to OAS with your application; OAS will check it", 'kind' => 'wait'],
                default => ['text' => "{$name}: uploaded; press Check with AI in My Documents so it counts", 'kind' => 'check'],
            };
        }

        return $todo;
    }

    /** Required documents that are not counted yet, sorted by what is wrong. */
    private static function documentGaps(array $checks): array
    {
        $gaps = ['missing' => [], 'problem' => [], 'unchecked' => []];

        foreach ($checks as $c) {
            if ($c['must'] || $c['result'] === 'met') {
                continue;
            }
            $value = (string) $c['value'];
            $bucket = $value === 'Not uploaded' || str_contains($value, 'expired') ? 'missing'
                : (str_contains($value, 'AI flagged') ? 'problem' : 'unchecked');
            $gaps[$bucket][] = $c['label'];
        }

        return $gaps;
    }

    private static function documentName(array $check): string
    {
        $rule = (string) ($check['rule'] ?? '');

        return str_starts_with($rule, self::DOC_PREFIX) ? substr($rule, strlen(self::DOC_PREFIX)) : $check['label'];
    }

    private static function after(string $text, string $marker): string
    {
        $at = strpos($text, $marker);

        return $at === false ? $text : substr($text, $at + strlen($marker));
    }
}
