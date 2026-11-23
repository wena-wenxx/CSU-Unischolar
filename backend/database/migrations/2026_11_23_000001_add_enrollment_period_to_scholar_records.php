<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The term whose enrollment was last checked for a grantee ("1st Semester AY 2026-2027").
| Payroll for another term asks for a new enrollment check first. Existing records keep null,
| which means "not recorded", and payroll treats them as before. Nothing is deleted.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('scholar_records', 'enrollment_period')) {
            Schema::table('scholar_records', function (Blueprint $table) {
                $table->string('enrollment_period', 100)->nullable()->after('currently_enrolled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('scholar_records', 'enrollment_period')) {
            Schema::table('scholar_records', function (Blueprint $table) {
                $table->dropColumn('enrollment_period');
            });
        }
    }
};
