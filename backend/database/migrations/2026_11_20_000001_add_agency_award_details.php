<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The details an agency list gives for each grantee (e.g. the CHED TES billing file):
| control number, award number, amount and batch. They are kept on the application
| (where the list is processed) and copied to the scholar record when the student is
| tagged as a grantee, so Scholar Records, payroll and exports can show them.
| Only adds nullable columns: existing data is not touched.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            if (! Schema::hasColumn('applications', 'agency_award_number')) {
                $table->string('agency_control_number', 20)->nullable()->after('remarks');
                $table->string('agency_award_number', 60)->nullable()->after('agency_control_number');
                $table->decimal('agency_amount', 12, 2)->nullable()->after('agency_award_number');
                $table->string('agency_batch', 30)->nullable()->after('agency_amount');
                $table->foreignId('agency_list_upload_id')->nullable()->after('agency_batch')
                    ->constrained('agency_list_uploads')->nullOnDelete();
            }
        });

        Schema::table('scholar_records', function (Blueprint $table) {
            if (! Schema::hasColumn('scholar_records', 'award_number')) {
                $table->string('control_number', 20)->nullable()->after('grantee_tagged_at');
                $table->string('award_number', 60)->nullable()->after('control_number');
                $table->decimal('award_amount', 12, 2)->nullable()->after('award_number');
                $table->string('award_batch', 30)->nullable()->after('award_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_list_upload_id');
            $table->dropColumn(['agency_control_number', 'agency_award_number', 'agency_amount', 'agency_batch']);
        });
        Schema::table('scholar_records', function (Blueprint $table) {
            $table->dropColumn(['control_number', 'award_number', 'award_amount', 'award_batch']);
        });
    }
};
