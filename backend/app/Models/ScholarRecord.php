<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
        'student_id', 'scholarship_id', 'status', 'currently_enrolled', 'has_atm', 'atm_funds', 'atm_note',
        'grantee_tagged_at', 'remarks',
    ];
    protected $casts = ['currently_enrolled' => 'boolean', 'has_atm' => 'boolean', 'grantee_tagged_at' => 'datetime'];

    protected $appends = ['atm_label'];

    public function student() { return $this->belongsTo(Student::class); }
    public function scholarship() { return $this->belongsTo(Scholarship::class); }
    public function payrollRecords() { return $this->hasMany(PayrollRecord::class); }

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
        foreach (self::with('scholarship:id,application_mode')->whereNull('atm_funds')->whereNull('atm_note')->get() as $record) {
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
