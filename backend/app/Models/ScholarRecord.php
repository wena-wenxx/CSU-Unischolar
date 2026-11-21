<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScholarRecord extends Model
{
    // Suggested notes when a grantee has no ATM yet (staff may type another).
    public const ATM_NOTES = [
        'For ATM application',
        'Pending bank processing',
        'ATM released, not yet activated',
        'Lost ATM, replacement requested',
    ];

    public const ATM_FUNDS = ['yes', 'no', 'pending'];

    protected $fillable = [
        'student_id', 'scholarship_id', 'school_year', 'semester', 'processing_batch_id', 'status', 'currently_enrolled', 'has_atm', 'atm_funds', 'atm_note',
        'grantee_tagged_at', 'remarks',
        // from the agency's approved list (e.g. the CHED TES billing file)
        'control_number', 'award_number', 'award_amount', 'award_batch',
    ];
    protected $casts = [
        'currently_enrolled' => 'boolean', 'has_atm' => 'boolean', 'grantee_tagged_at' => 'datetime',
        'award_amount' => 'decimal:2',
    ];

    protected $appends = ['atm_label'];

    /** A record made without a school year belongs to the current one (one record per program per year). */
    protected static function booted(): void
    {
        static::creating(function (ScholarRecord $record) {
            if (empty($record->school_year)) {
                $record->school_year = ExternalGrantee::currentSchoolYear();
            }
        });
    }

    public function student() { return $this->belongsTo(Student::class); }
    public function scholarship() { return $this->belongsTo(Scholarship::class); }
    public function payrollRecords() { return $this->hasMany(PayrollRecord::class); }
    public function processingBatch() { return $this->belongsTo(ProcessingBatch::class); }

    /*
    | Records inserted directly (the bulk demo seeder) get the school year of the
    | approved application that made them, or of the day they were tagged, and
    | that application's batch.
    */
    public static function fillMissingSchoolYears(): int
    {
        $count = 0;
        foreach (self::whereNull('school_year')->get() as $record) {
            $application = Application::where('student_id', $record->student_id)
                ->where('scholarship_id', $record->scholarship_id)
                ->where('status', 'approved')
                ->orderByDesc('school_year')->orderByDesc('id')
                ->first(['school_year', 'semester', 'processing_batch_id']);
            $record->timestamps = false;
            $record->update([
                'school_year' => $application?->school_year ?? Application::schoolYearOf($record->grantee_tagged_at ?? $record->created_at ?? now()),
                'semester' => $record->semester ?? $application?->semester,
                'processing_batch_id' => $record->processing_batch_id ?? $application?->processing_batch_id,
            ]);
            PayrollRecord::where('scholar_record_id', $record->id)->whereNull('processing_batch_id')
                ->update(['processing_batch_id' => $record->processing_batch_id]);
            $count++;
        }

        return $count;
    }

    /** "ATM · funded", "ATM · funds pending", "No ATM · For ATM application" ... */
    public function getAtmLabelAttribute(): string
    {
        if ($this->has_atm) {
            return match ($this->atm_funds) {
                'yes' => 'ATM · funded',
                'no' => 'ATM · no funds yet',
                'pending' => 'ATM · funds pending',
                default => 'ATM',
            };
        }

        return 'No ATM'.($this->atm_note ? ' · '.$this->atm_note : '');
    }

    /*
    | Starting ATM status for grantees that have none yet (migration and seeders):
    | ATM + already paid before -> funded; ATM, never paid -> pending;
    | no ATM -> "For ATM application".
    */
    public static function backfillAtmStatus(): int
    {
        $paid = DB::table('payroll_records')->where('status', 'processed')->pluck('scholar_record_id')->unique()->all();

        $count = 0;
        $records = self::query()->whereNull('atm_funds')->whereNull('atm_note');

        if (Schema::hasColumn('scholarships', 'application_mode')) {
            $records->with('scholarship:id,application_mode');
        }

        foreach ($records->get() as $record) {
            $record->timestamps = false;

            // Agency-direct programs pay the grantee themselves.
            if ($record->scholarship?->application_mode === 'agency_direct') {
                $record->forceFill(['atm_note' => 'Paid directly by the agency'])->save();
                $count++;
                continue;
            }

            $record->forceFill($record->has_atm
                ? ['atm_funds' => in_array($record->id, $paid) ? 'yes' : 'pending']
                : ['atm_note' => 'For ATM application'])->save();
            $count++;
        }

        return $count;
    }
}
