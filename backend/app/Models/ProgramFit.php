<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/* How well one scholarship fits one student's field (AI service). Ranks and explains only. */
class ProgramFit extends Model
{
    public $timestamps = false;

    protected $fillable = ['student_id', 'scholarship_id', 'level', 'similarity', 'fields', 'words', 'method', 'computed_at'];

    protected $casts = ['fields' => 'array', 'words' => 'array', 'similarity' => 'float', 'computed_at' => 'datetime'];

    public const LABELS = [
        'high' => 'Matches the field',
        'medium' => 'Close to the field',
        'low' => 'Other fields',
        'any' => 'Open to all fields',
    ];

    public function scholarship()
    {
        return $this->belongsTo(Scholarship::class);
    }

    /** For the API: {level, label, fields, words, method}. */
    public function summary(): array
    {
        return [
            'level' => $this->level,
            'label' => self::LABELS[$this->level] ?? $this->level,
            'fields' => $this->fields ?? [],
            'words' => $this->words ?? [],
            'similarity' => $this->similarity,
            'method' => $this->method,
        ];
    }
}
