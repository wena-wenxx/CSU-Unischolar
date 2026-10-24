<?php

namespace App\Support;

use App\Models\ProgramFit;
use App\Models\Scholarship;
use App\Models\Student;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
| AI field fit (round 12): asks the AI service how well each active
| scholarship fits each student's field of study, and saves the answer in
| program_fits. The AI service uses a pretrained sentence-embedding model
| (or TF-IDF when the model is not installed); see ai-service/fit_matcher.py.
|
| It never changes eligibility, scores or decisions: the rules in
| EligibilityEngine decide who is eligible. When the AI service is off,
| nothing happens and the old fit values stay.
*/
class FitScorer
{
    /** What the AI reads about a student: course, college, year and their own "field and goals". */
    public static function studentText(Student $s): string
    {
        return trim(implode('. ', array_filter([$s->course, $s->college, $s->year_level, $s->interests])));
    }

    /** What the AI reads about a program: its name, who runs it, and the description written by OAS. */
    public static function programText(Scholarship $p): string
    {
        return trim(implode('. ', array_filter([$p->name, $p->provider, $p->description,
            $p->colleges ? 'Colleges: '.implode(', ', $p->colleges) : null])));
    }

    /** @return array{ok: bool, method?: string, rows?: int, error?: string} */
    public static function refresh(?array $studentIds = null): array
    {
        $students = Student::query()
            ->whereHas('user', fn ($q) => $q->where('role', 'student')->where('is_active', true))
            ->when($studentIds !== null, fn ($q) => $q->whereIn('id', $studentIds))
            ->get();
        $programs = Scholarship::where('status', 'active')->get();
        if ($students->isEmpty() || $programs->isEmpty()) {
            return ['ok' => true, 'rows' => 0];
        }

        try {
            $response = Http::connectTimeout(3)->timeout(120)->post(rtrim(config('services.ai.url'), '/').'/fit-scores', [
                'students' => $students->map(fn ($s) => ['id' => $s->id, 'text' => self::studentText($s)])->values(),
                'programs' => $programs->map(fn ($p) => ['id' => $p->id, 'text' => self::programText($p)])->values(),
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'The AI service is not running.'];
        }
        if (!$response->successful()) {
            Log::warning('AI fit failed', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);

            return ['ok' => false, 'error' => 'The AI service could not score the fit.'];
        }

        $now = now();
        $rows = collect($response->json('scores') ?? [])->map(fn ($r) => [
            'student_id' => $r['student_id'],
            'scholarship_id' => $r['program_id'],
            'level' => $r['level'],
            'similarity' => $r['similarity'] ?? 0,
            'fields' => json_encode($r['fields'] ?? []),
            'words' => json_encode($r['words'] ?? []),
            'method' => $response->json('method') ?? 'tfidf',
            'computed_at' => $now,
        ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            ProgramFit::query()->upsert($chunk, ['student_id', 'scholarship_id'], ['level', 'similarity', 'fields', 'words', 'method', 'computed_at']);
        }
        // Programs that are no longer active.
        ProgramFit::whereIn('student_id', $students->pluck('id'))->whereNotIn('scholarship_id', $programs->pluck('id'))->delete();

        return ['ok' => true, 'method' => $response->json('method'), 'rows' => count($rows)];
    }

    /** Fits keyed "studentId-scholarshipId" for a set of rows (one query). */
    public static function lookup(iterable $pairs): array
    {
        $pairs = collect($pairs);
        if ($pairs->isEmpty()) {
            return [];
        }

        return ProgramFit::whereIn('student_id', $pairs->pluck(0)->unique())
            ->whereIn('scholarship_id', $pairs->pluck(1)->unique())
            ->get()
            ->keyBy(fn ($f) => $f->student_id.'-'.$f->scholarship_id)
            ->map(fn ($f) => $f->summary())
            ->all();
    }
}
