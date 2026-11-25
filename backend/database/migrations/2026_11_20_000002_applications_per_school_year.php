<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('applications', 'school_year')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->string('school_year', 9)->nullable()->after('scholarship_id');
            });
        }

        foreach (DB::table('applications')->whereNull('school_year')->get(['id', 'created_at']) as $row) {
            $date = $row->created_at ? \Illuminate\Support\Carbon::parse($row->created_at) : now();
            $start = $date->month >= 8 ? $date->year : $date->year - 1;
            DB::table('applications')->where('id', $row->id)->update(['school_year' => $start.'-'.($start + 1)]);
        }

        Schema::table('applications', function (Blueprint $table) {
            $table->unique(['student_id', 'scholarship_id', 'school_year'], 'applications_one_per_program_per_year');
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->dropUnique(['student_id', 'scholarship_id']);
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->unique(['student_id', 'scholarship_id']);
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->dropUnique('applications_one_per_program_per_year');
            $table->dropColumn('school_year');
        });
    }
};
