<?php

namespace App\Support;

use App\Models\ProgramFit;
use App\Models\Scholarship;
use App\Models\Student;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FitScorer
{
    public static function studentText(Student $s): string
    {
        return trim(implode('. ', array_filter([$s->course, $s->college, $s->year_level, $s->interests])));
    }

    public static function programText(Scholarship $p): string
    {
        return trim(implode('. ', array_filter([$p->name, $p->provider, $p->description,
            $p->colleges ? 'Colleges: '.implode(', ', $p->colleges) : null])));
    }

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
        ProgramFit::whereIn('student_id', $students->pluck('id'))->whereNotIn('scholarship_id', $programs->pluck('id'))->delete();

        return ['ok' => true, 'method' => $response->json('method'), 'rows' => count($rows)];
    }

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
