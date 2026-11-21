<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Scholar records per school year (requested by the OAS:
| "data or records per semester and academic year")
|--------------------------------------------------------------------------
| Before: one scholar record per student per program, for all years.
| After:  one scholar record per student per program PER SCHOOL YEAR, with the
|         semester and processing batch. Older years stay as history.
|
| Existing records get the school year of the approved application that made
| them (or of the date they were tagged), so no record is lost or merged.
| Payroll entries keep pointing to the same scholar record.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholar_records', function (Blueprint $table) {
            $table->string('school_year', 9)->nullable()->after('scholarship_id');
            $table->string('semester', 30)->nullable()->after('school_year');
            $table->foreignId('processing_batch_id')->nullable()->after('semester')
                ->constrained('processing_batches')->nullOnDelete();
        });

        foreach (DB::table('scholar_records')->get() as $record) {
            $application = DB::table('applications')
                ->where('student_id', $record->student_id)
                ->where('scholarship_id', $record->scholarship_id)
                ->where('status', 'approved')
                ->orderByDesc('school_year')->orderByDesc('id')
                ->first(['school_year', 'processing_batch_id']);

            $year = $application->school_year ?? self::schoolYearOf($record->grantee_tagged_at ?? $record->created_at);
            $batch = $application->processing_batch_id ?? DB::table('processing_batches')
                ->where('scholarship_id', $record->scholarship_id)->where('school_year', $year)->value('id');

            DB::table('scholar_records')->where('id', $record->id)
                ->update(['school_year' => $year, 'processing_batch_id' => $batch]);
        }

        // Payroll entries belong to the batch of their scholar record.
        foreach (DB::table('scholar_records')->whereNotNull('processing_batch_id')->get(['id', 'processing_batch_id']) as $record) {
            DB::table('payroll_records')->where('scholar_record_id', $record->id)
                ->update(['processing_batch_id' => $record->processing_batch_id]);
        }

        // New key first (it also serves the student_id foreign key in MySQL), then drop the old one.
        Schema::table('scholar_records', function (Blueprint $table) {
            $table->unique(['student_id', 'scholarship_id', 'school_year'], 'scholar_records_one_per_program_year');
        });
        Schema::table('scholar_records', function (Blueprint $table) {
            $table->dropUnique('scholar_records_one_per_program');
        });
    }

    public function down(): void
    {
        $clash = DB::table('scholar_records')->select('student_id', 'scholarship_id')
            ->groupBy('student_id', 'scholarship_id')->havingRaw('count(*) > 1')->exists();
        if ($clash) {
            throw new RuntimeException('Cannot roll back: some students already have scholar records for more than one school year of the same program.');
        }

        Schema::table('scholar_records', function (Blueprint $table) {
            $table->unique(['student_id', 'scholarship_id'], 'scholar_records_one_per_program');
        });
        Schema::table('scholar_records', function (Blueprint $table) {
            $table->dropUnique('scholar_records_one_per_program_year');
            $table->dropConstrainedForeignId('processing_batch_id');
            $table->dropColumn(['school_year', 'semester']);
        });
    }

    /** "2026-2027" for any date (a school year starts in August). */
    private static function schoolYearOf($date): string
    {
        $date = \Illuminate\Support\Carbon::parse($date ?? now());
        $start = $date->month >= 8 ? $date->year : $date->year - 1;

        return $start.'-'.($start + 1);
    }
};
