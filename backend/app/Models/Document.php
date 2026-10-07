<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    /*
    | How long each kind of document counts as current, in months from the
    | day it was uploaded. null = does not expire.
    | These are SUGGESTED values for the prototype; confirm them with the OAS
    | and change them here.
    */
    public const VALIDITY_MONTHS = [
        'Certificate of Registration (COR)' => 6,     // one semester
        'Certificate of Grades' => 6,                 // one semester
        'Certificate of Indigency' => 6,
        'Barangay Clearance' => 6,
        'Certificate of Good Moral Character' => 6,
        'Recommendation Letter' => 6,
        "Parents' Income Tax Return" => 12,
        'Birth Certificate' => null,
        'Valid ID' => null,
    ];

    protected $fillable = [
        'student_id', 'application_id', 'scholarship_requirement_id', 'original_filename', 'file_path', 'document_type', 'status',
    ];

    protected static function booted(): void
    {
        // Every document belongs to a student: take it from the application
        // when the caller did not set it.
        static::creating(function (Document $document) {
            if (!$document->student_id && $document->application_id) {
                $document->student_id = Application::whereKey($document->application_id)->value('student_id');
            }
        });
    }

    public function application() { return $this->belongsTo(Application::class); }
    public function student() { return $this->belongsTo(Student::class); }
    public function requirement() { return $this->belongsTo(ScholarshipRequirement::class, 'scholarship_requirement_id'); }
    public function validationResult() { return $this->hasOne(ValidationResult::class); }

    // "Certificate of Grades", taken from the requirement or the saved type.
    public function typeName(): string
    {
        return $this->requirement?->name ?: ($this->document_type ?: 'Other document');
    }

    public function expiresAt(): ?Carbon
    {
        $type = $this->typeName();

        if (!array_key_exists($type, self::VALIDITY_MONTHS) || self::VALIDITY_MONTHS[$type] === null) {
            return null;
        }

        return $this->created_at?->copy()->addMonths(self::VALIDITY_MONTHS[$type]);
    }

    public function isExpired(): bool
    {
        $expires = $this->expiresAt();

        return $expires !== null && $expires->isPast();
    }

    // Deletes the stored file only if no other document row still uses it
    // (a reused document shares one file between applications).
    public static function deleteFileIfUnused(string $path, ?int $exceptId = null): void
    {
        $stillUsed = self::where('file_path', $path)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        if (!$stillUsed) {
            Storage::disk('public')->delete($path);
        }
    }

    // Seeded documents are created "now"; date them to the day before their
    // application was submitted, so expiry and history look real.
    public static function alignSeededDates(): int
    {
        $count = 0;

        $rows = self::query()
            ->join('applications', 'applications.id', '=', 'documents.application_id')
            ->whereNotNull('applications.submitted_at')
            ->whereColumn('documents.created_at', '>', 'applications.submitted_at')
            ->get(['documents.id', 'applications.submitted_at']);

        foreach ($rows as $row) {
            $at = Carbon::parse($row->submitted_at)->subDay();
            DB::table('documents')->where('id', $row->id)->update(['created_at' => $at, 'updated_at' => $at]);
            $count++;
        }

        return $count;
    }

    // Fills student_id on rows inserted directly by the bulk seeders.
    public static function backfillOwners(): int
    {
        return DB::update('UPDATE documents SET student_id = (SELECT applications.student_id FROM applications WHERE applications.id = documents.application_id) WHERE student_id IS NULL AND application_id IS NOT NULL');
    }
}
