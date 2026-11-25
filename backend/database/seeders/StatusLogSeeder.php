<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StatusLogSeeder extends Seeder
{
    private const NAMED_PREFIX = '2026-000';

    public function run(): void
    {
        if (DB::table('application_status_logs')->exists()) {
            $this->command?->info('Status history already exists - skipped.');
            return;
        }

        $now = now();
        $staffId = DB::table('users')->where('role', 'staff')->value('id');

        $tagged = DB::table('scholar_records')
            ->select('student_id', 'scholarship_id', 'grantee_tagged_at')
            ->get()
            ->keyBy(fn ($r) => $r->student_id.'-'.$r->scholarship_id);

        $applications = DB::table('applications')
            ->join('students', 'students.id', '=', 'applications.student_id')
            ->select('applications.*', 'students.user_id', 'students.student_id as school_id')
            ->orderBy('applications.id')
            ->get();

        $rows = [];
        $lastTimes = [];

        foreach ($applications as $app) {
            $named = str_starts_with($app->school_id, self::NAMED_PREFIX);
            $steps = $this->steps($app, $tagged[$app->student_id.'-'.$app->scholarship_id] ?? null, $now);

            foreach ($steps as $i => [$to, $at, $remarks, $byStaff]) {
                $isLast = $i === array_key_last($steps);
                $rows[] = [
                    'application_id' => $app->id,
                    'from_status' => $i === 0 ? null : $this->statusBefore($steps, $i),
                    'to_status' => $to,
                    'remarks' => $remarks,
                    'changed_by' => $byStaff ? $staffId : $app->user_id,
                    'read_at' => ($named && $isLast && $to !== 'draft') ? null : $at,
                    'created_at' => $at,
                    'updated_at' => $at,
                ];
            }

            $lastTimes[$app->id] = end($steps)[1];
        }

        DB::transaction(function () use ($rows, $lastTimes) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('application_status_logs')->insert($chunk);
            }

            foreach ($lastTimes as $id => $at) {
                DB::table('applications')->where('id', $id)->update(['updated_at' => $at]);
            }
        });

        $this->command?->info('Status history: '.count($rows).' steps for '.count($lastTimes).' applications.');
    }

    private function statusBefore(array $steps, int $i): string
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!in_array($steps[$j][0], ['enrollment_verified', 'grantee_tagged'], true)) {
                return $steps[$j][0];
            }
        }

        return 'draft';
    }

    private function steps(object $app, ?object $record, Carbon $now): array
    {
        $created = Carbon::parse($app->created_at);
        $submitted = $app->submitted_at ? Carbon::parse($app->submitted_at) : null;
        $verified = $app->enrollment_verified_at ? Carbon::parse($app->enrollment_verified_at) : null;

        $started = $submitted ? $created->copy()->min($submitted->copy()->subDay()) : $created;
        $steps = [['draft', $started, null, false]];

        if (!$submitted) {
            return $steps;
        }

        $steps[] = ['submitted', $submitted, null, false];

        $path = match ($app->status) {
            'under_review' => ['under_review'],
            'needs_action' => ['under_review', 'needs_action'],
            'complete' => ['under_review', 'complete'],
            'approved' => ['under_review', 'complete', 'approved'],
            'rejected' => ($app->id % 3 === 0) ? ['rejected'] : ['under_review', 'complete', 'rejected'],
            default => [],
        };

        if (!$path) {
            return $steps;
        }

        $latest = $now->copy()->subHours(1 + $app->id % 20);
        $end = $verified
            ? $verified->copy()->subHours(6)
            : $submitted->copy()->addDays(2 + $app->id % 12)->addHours($app->id % 9);
        $end = $end->min($latest)->max($submitted->copy()->addHours(2));

        $span = $submitted->diffInSeconds($end);
        $count = count($path);

        foreach ($path as $k => $status) {
            $at = $submitted->copy()->addSeconds((int) round($span * ($k + 1) / $count));
            $remarks = in_array($status, ['needs_action', 'rejected'], true) ? $app->remarks : null;
            $steps[] = [$status, $at, $remarks, true];
        }

        if ($verified) {
            $steps[] = ['enrollment_verified', $verified, null, true];
        }

        if ($record && $record->grantee_tagged_at && $app->status === 'approved') {
            $taggedAt = Carbon::parse($record->grantee_tagged_at)->max(($verified ?? $submitted)->copy()->addHour());
            $steps[] = ['grantee_tagged', $taggedAt, null, true];
        }

        return $steps;
    }
}
