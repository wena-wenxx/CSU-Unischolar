<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/*
| Audit trail for AI Recommendations: every recommendation shown to staff
| and every staff decision, with the score at that moment.
*/
class RecommendationLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = [
        'shown' => 'Shown to staff',
        'invited' => 'Invitation sent',
        'added' => 'Added to scholarship (draft application)',
        'dismissed' => 'Dismissed by staff',
        'wrong' => 'Marked as wrong',
        'restored' => 'Restored',
        'recalculated' => 'Recalculated',
    ];

    protected $fillable = [
        'ai_recommendation_id', 'student_id', 'scholarship_id', 'user_id', 'action', 'score', 'verdict', 'note', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function recommendation() { return $this->belongsTo(AiRecommendation::class, 'ai_recommendation_id'); }
    public function student() { return $this->belongsTo(Student::class); }
    public function scholarship() { return $this->belongsTo(Scholarship::class); }
    public function user() { return $this->belongsTo(User::class); }

    public static function record(AiRecommendation $recommendation, ?User $user, string $action, ?string $note = null): self
    {
        return self::create([
            'ai_recommendation_id' => $recommendation->id,
            'student_id' => $recommendation->student_id,
            'scholarship_id' => $recommendation->scholarship_id,
            'user_id' => $user?->id,
            'action' => $action,
            'score' => $recommendation->score,
            'verdict' => $recommendation->verdict,
            'note' => $note,
        ]);
    }

    /** One "shown" row per recommendation on the page the staff member just opened. */
    public static function recordShown(iterable $recommendations, ?User $user): void
    {
        $now = now();
        $rows = [];

        foreach ($recommendations as $recommendation) {
            $rows[] = [
                'ai_recommendation_id' => $recommendation->id,
                'student_id' => $recommendation->student_id,
                'scholarship_id' => $recommendation->scholarship_id,
                'user_id' => $user?->id,
                'action' => 'shown',
                'score' => $recommendation->score,
                'verdict' => $recommendation->verdict,
                'note' => null,
                'created_at' => $now,
            ];
        }

        try {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('recommendation_logs')->insert($chunk);
            }
        } catch (\Throwable $e) {
            report($e); // logging never stops the page from loading
        }
    }
}
