<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
