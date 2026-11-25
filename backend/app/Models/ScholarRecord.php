<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScholarRecord extends Model
{
    public const ATM_NOTES = [
        'For ATM application',
        'Pending bank processing',
        'ATM released, not yet activated',
        'Lost ATM, replacement requested',
    ];

    public const ATM_FUNDS = ['yes', 'no', 'pending'];

    protected $fillable = [
        'student_id', 'scholarship_id', 'school_year', 'semester', 'processing_batch_id', 'status', 'currently_enrolled', 'enrollment_period', 'has_atm', 'atm_funds', 'atm_note',
        'grantee_tagged_at', 'remarks',
        'control_number', 'award_number', 'award_amount', 'award_batch',
    ];
    protected $casts = [
        'currently_enrolled' => 'boolean', 'has_atm' => 'boolean', 'grantee_tagged_at' => 'datetime',
        'award_amount' => 'decimal:2',
    ];

    protected $appends = ['atm_label'];

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
