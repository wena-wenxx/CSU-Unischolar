<?php

use App\Models\ScholarRecord;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholar_records', function (Blueprint $table) {
            if (! Schema::hasColumn('scholar_records', 'atm_funds')) {
                $table->string('atm_funds', 10)->nullable()->after('has_atm');
            }
            if (! Schema::hasColumn('scholar_records', 'atm_note')) {
                $table->string('atm_note', 100)->nullable()->after('atm_funds');
            }
        });

        Schema::table('payroll_records', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_records', 'prepared_by')) {
                $table->foreignId('prepared_by')->nullable()->after('signature')
                    ->constrained('users')->nullOnDelete();
            }
        });

        ScholarRecord::backfillAtmStatus();
    }

    public function down(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prepared_by');
        });

        Schema::table('scholar_records', function (Blueprint $table) {
            $table->dropColumn(['atm_funds', 'atm_note']);
        });
    }
};
