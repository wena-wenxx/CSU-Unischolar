<?php

namespace App\Support;

use App\Models\AiRecommendation;

class InvitationText
{
    public static function level(AiRecommendation $r): string
    {
        if ($r->verdict === 'not_eligible') {
            return 'low';
        }

        $open = collect((array) $r->checks)->contains(fn ($c) => in_array(((array) $c)['result'] ?? 'no_data', ['no_data', 'not_met'], true));

        return $r->verdict === 'eligible' && (int) $r->score >= 80 && !$open ? 'high' : 'partial';
    }

    public static function levelLabel(string $level): string
    {
        return ['high' => 'High match', 'partial' => 'Partial match', 'low' => 'Low match'][$level] ?? 'Match';
    }

    public static function details(AiRecommendation $r): string
    {
        $checks = collect((array) $r->checks)->map(fn ($c) => (array) $c + ['label' => 'A rule', 'rule' => '', 'value' => 'not recorded',
            'result' => 'no_data', 'must' => false, 'points' => 1]);
        $met = $checks->where('result', 'met')->pluck('label')->values();
        $notMet = $checks->where('result', 'not_met');
        $noData = $checks->where('result', 'no_data');
        $unverified = $checks->where('result', 'met')->filter(fn ($c) => ($c['points'] ?? 1) < 1 && !empty($c['must']))->pluck('label');

        $lines = [];
        $level = self::level($r);
        $lines[] = match ($level) {
            'high' => 'You appear to meet the listed criteria of this scholarship.',
            'partial' => 'You may be eligible, but some information still needs to be completed or checked.',
            default => 'Some listed criteria do not appear to be met yet, but the OAS asked us to let you know about this opportunity.',
        };

        if ($met->isNotEmpty()) {
            $lines[] = 'Appears to be satisfied: '.$met->implode(', ').'.';
        }
        if ($notMet->isNotEmpty()) {
            $lines[] = 'Does not appear to be met: '.$notMet->map(fn ($c) => $c['label'].($c['rule'] ? " ({$c['rule']}; on record: {$c['value']})" : ''))->implode('; ').'.';
        }
        if ($noData->isNotEmpty()) {
            $lines[] = 'Missing or not yet checked: '.$noData->map(fn ($c) => "{$c['label']} ({$c['value']})")->implode('; ').'.';
        }
        if ($unverified->isNotEmpty()) {
            $lines[] = 'Not yet verified by the OAS: '.$unverified->implode(', ').'.';
        }

        $next = match ($level) {
            'high' => 'Next step: read the official requirements and apply in CSU UniScholar before the deadline.',
            'partial' => 'Next step: complete your profile (GWA, family income) and upload the missing documents in My Documents, then apply if you meet the official requirements.',
            default => 'Next step: read the official requirements first. Apply only if they apply to you, or contact the OAS if your information on record is wrong.',
        };
        $lines[] = $next;

        return implode("\n", $lines);
    }

    public static function invitation(AiRecommendation $r, string $name, ?string $note): string
    {
        return "Dear {$name},\n\nThe Office of Admission and Scholarship invites you to look at {$r->scholarship->name}.\n\n"
            .self::details($r)
            .($note ? "\n\nNote from the OAS: {$note}" : '')
            ."\n\nThis invitation is not an approval. Your application is still subject to the scholarship requirements, OAS review, and the decision of the scholarship provider.";
    }
}
