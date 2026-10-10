<?php

use App\Models\ScholarRecord;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| ATM status of each grantee (status tracking only; the system does NOT
| handle money or talk to any bank).
|   has_atm   (already existed)  yes / no
|   atm_funds  yes / no / pending  -> has the stipend reached the ATM?
|   atm_note   e.g. "For ATM application", "Pending bank processing"
| payroll_records.prepared_by: which staff member prepared the entry.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholar_records', function (Blueprint $table) {
            $table->string('atm_funds', 10)->nullable()->after('has_atm');
            $table->string('atm_note', 100)->nullable()->after('atm_funds');
        });

        Schema::table('payroll_records', function (Blueprint $table) {
            $table->foreignId('prepared_by')->nullable()->after('signature')
                ->constrained('users')->nullOnDelete();
        });

        // Give existing grantees a starting value.
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
