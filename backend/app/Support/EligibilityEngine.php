<?php

namespace App\Support;

use App\Models\AiRecommendation;
use App\Models\Application;
use App\Models\Document;
use App\Models\ExternalGrantee;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| AI Recommendations: the rule-based eligibility engine
|--------------------------------------------------------------------------
| For every active scholarship that has at least one rule or required
| document, and every active student account, it runs a list of CHECKS:
|
|   must-have rules (typed in by OAS on the Scholarships page)
|     GWA            student's GWA <= the scholarship's highest GWA allowed
|     Family income  student's annual family income <= the ceiling
|     Year level     student's year level is in the allowed list
|     College        student's college is in the allowed list
|     One scholarship  the student holds no other active scholarship
|                      (ScholarGuide grantee records + external lists)
|   documents (the scholarship's REQUIRED documents)
|     met ONLY when the student has the document saved, not expired, and the
|     AI document check found no problem. A file the AI has not checked yet
|     (or could not read) does not count, so 100% cannot come from unchecked
|     files.
| GWA and family income typed by the student still count, but every row
| says whether OAS has checked them (data_verified), and staff can show
| only students whose data OAS checked.
|
| Every check has equal weight:
|     score = checks met / all checks x 100   (rounded)
| A must-have rule that is NOT met makes the verdict "not_eligible", even
| when the score is high; a must-have rule that cannot be checked (no GWA
| entered yet) makes it "no_data". Only "eligible" rows are recommended by
| default. Nothing here approves, rejects or changes an application: staff
| decide on the AI Recommendations page.
|
| Not checked (on purpose): sex, name, or anything the scholarship does not
| ask for, so two students with the same data always get the same score.
*/
class EligibilityEngine
{
    // Short names for the table (full names stay in the details).
    public const SHORT = [
        'Certificate of Registration (COR)' => 'COR',
        'Certificate of Grades' => 'Grades',
        'Valid ID' => 'Valid ID',
        'Certificate of Indigency' => 'Indigency',
        'Birth Certificate' => 'Birth Cert.',
        'Barangay Clearance' => 'Brgy. Clearance',
        'Certificate of Good Moral Character' => 'Good Moral',
        "Parents' Income Tax Return" => 'ITR',
        'Recommendation Letter' => 'Recommendation',
    ];

    /** Scholarships the engine scores: active, with at least one rule or required document. */
    public static function scoredScholarships(): Collection
    {
        return Scholarship::with('requirements')
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->filter(fn (Scholarship $s) => self::hasRules($s) || $s->requirements->where('is_required', true)->isNotEmpty())
            ->values();
    }

    public static function hasRules(Scholarship $s): bool
    {
        return $s->max_gwa !== null || $s->max_family_income !== null
            || !empty($s->year_levels) || !empty($s->colleges);
    }

    /*
    | Recalculate the recommendations. $studentIds = null -> every student.
    | Staff decisions (invited, dismissed, ...) are kept; only the numbers change.
    | Returns ['students' => n, 'scholarships' => n, 'rows' => n, 'eligible' => n].
    */
    public static function recalculate(?array $studentIds = null): array
    {
        $scholarships = self::scoredScholarships();

        $students = Student::query()
            ->whereHas('user', fn ($q) => $q->where('role', 'student')->where('is_active', true))
            ->when($studentIds !== null, fn ($q) => $q->whereIn('id', $studentIds))
            ->get();

        $ids = $students->pluck('id')->all();
        $context = self::context($ids);
        $now = now();
        $rows = [];
        $keep = [];

        foreach ($students as $student) {
            foreach ($scholarships as $scholarship) {
                $key = $student->id.'-'.$scholarship->id;
                $application = $context['applications'][$key] ?? null;

                // Already a grantee of this program, or already approved: nothing to recommend.
                $isGrantee = ($context['records'][$student->id] ?? collect())
                    ->contains(fn ($r) => $r->scholarship_id === $scholarship->id);
                if ($isGrantee || $application?->status === 'approved') {
                    continue;
                }

                $result = self::evaluate($student, $scholarship, $context);
                $keep[$key] = true;

                $rows[] = [
                    'student_id' => $student->id,
                    'scholarship_id' => $scholarship->id,
                    'score' => $result['score'],
                    'met_count' => $result['met'],
                    'total_count' => $result['total'],
                    'verdict' => $result['verdict'],
                    'checks' => json_encode($result['checks']),
                    'calculation' => $result['calculation'],
                    'applied_status' => $application?->status,
                    'data_verified' => self::dataVerified($student),
                    'computed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::transaction(function () use ($rows, $ids, $keep, $studentIds) {
            foreach (array_chunk($rows, 300) as $chunk) {
                AiRecommendation::query()->upsert(
                    $chunk,
                    ['student_id', 'scholarship_id'],
                    ['score', 'met_count', 'total_count', 'verdict', 'checks', 'calculation', 'applied_status',
                        'data_verified', 'computed_at', 'updated_at']
                );
            }

            // Rows that no longer apply (student became a grantee, program closed, ...):
            // remove the untouched ones; keep the ones staff already acted on.
            AiRecommendation::query()
                ->where('staff_status', 'new')
                ->when($studentIds !== null, fn ($q) => $q->whereIn('student_id', $ids))
                ->get(['id', 'student_id', 'scholarship_id'])
                ->reject(fn ($r) => isset($keep[$r->student_id.'-'.$r->scholarship_id]))
                ->each(fn ($r) => $r->delete());
        });

        // AI field fit (ranks and explains only; skipped quietly when the AI service is off).
        $fit = FitScorer::refresh($studentIds);

        return [
            'students' => $students->count(),
            'scholarships' => $scholarships->count(),
            'rows' => count($rows),
            'eligible' => count(array_filter($rows, fn ($r) => $r['verdict'] === 'eligible')),
            'fit' => $fit,
        ];
    }

    /** Everything the checks need, loaded once (no query per student). */
    private static function context(array $studentIds): array
    {
        $records = ScholarRecord::with('scholarship:id,name')
            ->where('status', 'active')
            ->whereIn('student_id', $studentIds)
            ->get()
            ->groupBy('student_id');

        $external = ExternalGrantee::query()
            ->where('match_status', 'matched')
            ->where('status', 'active')
            ->whereIn('student_id', $studentIds)
            ->get()
            ->groupBy('student_id');

        $applications = Application::query()
            ->whereIn('student_id', $studentIds)
            ->get(['id', 'student_id', 'scholarship_id', 'status'])
            ->keyBy(fn ($a) => $a->student_id.'-'.$a->scholarship_id);

        // Newest copy of each document type per student (My Documents + applications).
        $documents = [];
        Document::with(['requirement:id,name', 'validationResult:id,document_id,flags'])
            ->whereIn('student_id', $studentIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (Document $d) use (&$documents) {
                $documents[$d->student_id][$d->typeName()] = $d;
            });

        return compact('records', 'external', 'applications', 'documents');
    }

    public static function dataVerified(Student $student): bool
    {
        return $student->eligibility_verified_at !== null
            && ($student->eligibility_updated_at === null || $student->eligibility_verified_at->gte($student->eligibility_updated_at));
    }

    /** All checks for one student and one scholarship. */
    public static function evaluate(Student $student, Scholarship $scholarship, array $context): array
    {
        $checks = [];
        $verified = self::dataVerified($student);
        $source = $verified ? 'verified by OAS' : 'typed by the student, not yet verified';

        if ($scholarship->max_gwa !== null) {
            $limit = (float) $scholarship->max_gwa;
            $gwa = $student->gwa !== null ? (float) $student->gwa : null;
            $checks[] = self::check('gwa', 'GWA', true,
                'GWA of '.number_format($limit, 2).' or better (1.00 is the highest grade)',
                $gwa === null ? 'Not entered' : number_format($gwa, 2)." ({$source})",
                $gwa === null ? 'no_data' : ($gwa <= $limit ? 'met' : 'not_met'));
        }

        if ($scholarship->max_family_income !== null) {
            $limit = (float) $scholarship->max_family_income;
            $income = $student->family_income !== null ? (float) $student->family_income : null;
            $checks[] = self::check('income', 'Family income', true,
                'Annual family income of PHP '.number_format($limit, 0).' or less',
                $income === null ? 'Not entered' : 'PHP '.number_format($income, 0)." ({$source})",
                $income === null ? 'no_data' : ($income <= $limit ? 'met' : 'not_met'));
        }

        if (!empty($scholarship->year_levels)) {
            $checks[] = self::check('year_level', 'Year level', true,
                'Year level: '.implode(', ', $scholarship->year_levels),
                $student->year_level ?: 'Not recorded',
                !$student->year_level ? 'no_data' : (self::inList($student->year_level, $scholarship->year_levels) ? 'met' : 'not_met'));
        }

        if (!empty($scholarship->colleges)) {
            $checks[] = self::check('college', 'College', true,
                'College: '.implode(', ', $scholarship->colleges),
                $student->college ?: 'Not recorded',
                !$student->college ? 'no_data' : (self::inList($student->college, $scholarship->colleges) ? 'met' : 'not_met'));
        }

        if ($scholarship->one_scholarship_only) {
            $other = ($context['records'][$student->id] ?? collect())
                ->first(fn ($r) => $r->scholarship_id !== $scholarship->id);
            $external = ($context['external'][$student->id] ?? collect())->first();
            $holding = $other ? 'Active grantee of '.($other->scholarship?->name ?? 'another program')
                : ($external ? 'Holds '.$external->label() : null);

            $checks[] = self::check('one_scholarship', 'No other scholarship', true,
                'Holds no other active scholarship (one active scholarship per student)',
                $holding ?? 'None found in ScholarGuide or the external lists',
                $holding ? 'not_met' : 'met');
        }

        foreach ($scholarship->requirements->where('is_required', true) as $requirement) {
            $document = $context['documents'][$student->id][$requirement->name] ?? null;
            [$value, $result] = self::documentState($document);

            $checks[] = self::check('doc:'.$requirement->id, self::SHORT[$requirement->name] ?? $requirement->name, false,
                'Required document: '.$requirement->name, $value, $result);
        }

        $total = count($checks);
        $met = count(array_filter($checks, fn ($c) => $c['result'] === 'met'));
        $musts = array_filter($checks, fn ($c) => $c['must']);
        $mustMet = count(array_filter($musts, fn ($c) => $c['result'] === 'met'));
        $docs = $total - count($musts);
        $docsMet = $met - $mustMet;

        $verdict = 'eligible';
        if (array_filter($musts, fn ($c) => $c['result'] === 'not_met')) {
            $verdict = 'not_eligible';
        } elseif (array_filter($musts, fn ($c) => $c['result'] === 'no_data')) {
            $verdict = 'no_data';
        }

        $score = $total ? (int) round($met / $total * 100) : 0;

        return [
            'checks' => $checks,
            'met' => $met,
            'total' => $total,
            'score' => $score,
            'verdict' => $verdict,
            'calculation' => "{$met} of {$total} checks met ({$mustMet} of ".count($musts)." must-have rules, {$docsMet} of {$docs} documents) = {$score}%",
        ];
    }

    private static function check(string $key, string $label, bool $must, string $rule, string $value, string $result): array
    {
        return compact('key', 'label', 'must', 'rule', 'value', 'result');
    }

    private static function inList(string $value, array $list): bool
    {
        $needle = mb_strtolower(trim($value));

        return collect($list)->contains(fn ($item) => mb_strtolower(trim((string) $item)) === $needle);
    }

    /** [what the staff member sees, met / not_met] for the student's newest copy of a document. */
    private static function documentState(?Document $document): array
    {
        if (!$document) {
            return ['Not uploaded', 'not_met'];
        }

        $saved = 'Saved '.$document->created_at?->format('M j, Y');

        if ($document->isExpired()) {
            return [$saved.', expired on '.$document->expiresAt()?->format('M j, Y'), 'not_met'];
        }

        // A person's check (e.g. PhilSys Check, Registrar, barangay) counts even when the AI could not read it.
        if ($document->staff_verified_at) {
            return [$saved.' · verified by OAS staff', 'met'];
        }

        return match ($document->status) {
            'flagged' => [$saved.' · AI flagged: '.self::firstFlag($document), 'not_met'],
            'needs_review' => [$saved.' · the AI could not read it (check by hand)', 'not_met'],
            'validated' => [$saved.' · AI check: no problems', 'met'],
            // Not checked yet: it may be fine, but it is not counted until the AI checks it.
            default => [$saved.' · not AI-checked yet (run the AI check to count it)', 'not_met'],
        };
    }

    private static function firstFlag(Document $document): string
    {
        $flags = (string) $document->validationResult?->flags;

        return trim(strtok($flags, "\n")) ?: 'possible problem';
    }
}
