<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgencyListUpload extends Model
{
    /** Row results that need a person: no student found, or something to check. */
    public const OPEN_RESULTS = ['unmatched', 'error', 'check'];

    protected $fillable = [
        'scholarship_id', 'processing_batch_id', 'agency_name', 'campus', 'file_name', 'file_sha256', 'uploaded_by', 'total_rows',
        'approved_count', 'created_count', 'already_count', 'unmatched_count', 'error_count', 'details',
        'school_year', 'semester', 'batch', 'reference_number', 'list_date',
        'expected_count', 'expected_total', 'notes', 'done_at', 'done_by', 'done_note',
    ];

    protected $casts = [
        'details' => 'array',
        'list_date' => 'date:Y-m-d',
        'expected_count' => 'integer',
        'expected_total' => 'decimal:2',
        'done_at' => 'datetime',
    ];

    public function scholarship()
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function processingBatch()
    {
        return $this->belongsTo(ProcessingBatch::class);
    }

    /** Rows still unmatched or to check, not yet linked or set aside by staff. */
    public function openRows(): array
    {
        return array_values(array_filter($this->details ?? [], fn ($row) => in_array($row['result'] ?? null, self::OPEN_RESULTS, true)
            && empty($row['resolved_at'])));
    }

    public function openRowCount(): int
    {
        return count($this->openRows());
    }
}
