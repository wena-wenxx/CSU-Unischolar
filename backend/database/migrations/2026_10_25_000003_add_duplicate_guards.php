<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The database itself refuses duplicates (the code already checks, but two
| clicks at the same moment could slip past the code):
|   scholar_records: one record per student per program
|   payroll_records: one entry per scholar record per period
| If old data already has duplicates, the migration stops and lists them so
| OAS can decide which one to keep (nothing is deleted automatically).
*/
return new class extends Migration
{
    public function up(): void
    {
        $this->stopIfDuplicates('scholar_records', ['student_id', 'scholarship_id']);
        $this->stopIfDuplicates('payroll_records', ['scholar_record_id', 'period']);

        Schema::table('scholar_records', fn (Blueprint $t) => $t->unique(['student_id', 'scholarship_id'], 'scholar_records_one_per_program'));
        Schema::table('payroll_records', fn (Blueprint $t) => $t->unique(['scholar_record_id', 'period'], 'payroll_records_one_per_period'));
    }

    public function down(): void
    {
        Schema::table('scholar_records', fn (Blueprint $t) => $t->dropUnique('scholar_records_one_per_program'));
        Schema::table('payroll_records', fn (Blueprint $t) => $t->dropUnique('payroll_records_one_per_period'));
    }

    private function stopIfDuplicates(string $table, array $columns): void
    {
        $dupes = DB::table($table)->select($columns)->selectRaw('count(*) as n')
            ->groupBy($columns)->havingRaw('count(*) > 1')->get();

        if ($dupes->isNotEmpty()) {
            throw new RuntimeException("{$table} has duplicate rows for ".implode(' + ', $columns).': '
                .$dupes->map(fn ($d) => json_encode($d))->implode(', ')
                .'. Remove the extra rows (keep the correct one), then run php artisan migrate again.');
        }
    }
};
