<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'user_name', 'role', 'action', 'description', 'subject_type', 'subject_id', 'ip_address', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /*
    | ActivityLog::record($user, 'application.approved', 'Approved by agency: Juan Student (TES)', $application)
    | Never stops the action it describes: if logging fails, the error is ignored.
    */
    public static function record(?User $user, string $action, string $description, ?Model $subject = null): ?self
    {
        try {
            return self::create([
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'role' => $user?->role,
                'action' => $action,
                'description' => mb_substr($description, 0, 2000),
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'ip_address' => request()?->ip(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /*
    | Demo data only: one activity entry for each staff step already in the
    | application history (seeders), so Activity Logs is not empty at first.
    */
    public static function backfillFromStatusLogs(): int
    {
        if (self::query()->exists()) {
            return 0;
        }

        $labels = [
            'under_review' => ['application.under_review', 'Marked under review'],
            'needs_action' => ['application.needs_action', 'Asked for corrections (needs action)'],
            'complete' => ['application.forwarded', 'Forwarded to the agency'],
            'approved' => ['application.approved', 'Recorded agency approval'],
            'rejected' => ['application.rejected', 'Recorded agency rejection'],
            'enrollment_verified' => ['enrollment.verified', 'Verified enrollment'],
            'grantee_tagged' => ['grantee.tagged', 'Tagged as grantee'],
        ];

        $users = User::whereIn('role', ['staff', 'admin'])->get()->keyBy('id');
        $rows = [];

        ApplicationStatusLog::with('application.student:id,first_name,last_name', 'application.scholarship:id,name')
            ->whereIn('to_status', array_keys($labels))
            ->whereNotNull('changed_by')
            ->orderBy('created_at')
            ->chunk(500, function ($logs) use (&$rows, $labels, $users) {
                foreach ($logs as $log) {
                    $user = $users[$log->changed_by] ?? null;
                    if (!$user) {
                        continue;
                    }
                    $student = $log->application?->student;
                    [$action, $text] = $labels[$log->to_status];
                    $rows[] = [
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'role' => $user->role,
                        'action' => $action,
                        'description' => $text.': '.trim(($student?->first_name ?? '').' '.($student?->last_name ?? ''))
                            .' ('.($log->application?->scholarship?->name ?? 'program').').',
                        'subject_type' => 'Application',
                        'subject_id' => $log->application_id,
                        'ip_address' => null,
                        'created_at' => $log->created_at,
                    ];
                }
            });

        foreach (array_chunk($rows, 500) as $chunk) {
            self::query()->insert($chunk);
        }

        return count($rows);
    }
}
