<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('agency_submissions')) {
            Schema::create('agency_submissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('scholarship_id')->constrained()->cascadeOnDelete();
                $table->foreignId('processing_batch_id')->nullable()->constrained()->nullOnDelete();
                $table->date('sent_on');
                $table->string('agency_name')->nullable();
                $table->string('sent_by_way', 100)->nullable();
                $table->text('note')->nullable();
                $table->unsignedInteger('application_count')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasColumn('applications', 'agency_submission_id')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->foreignId('agency_submission_id')->nullable()->after('forwarded_at')
                    ->constrained('agency_submissions')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('agency_list_uploads', 'done_at')) {
            Schema::table('agency_list_uploads', function (Blueprint $table) {
                $table->timestamp('done_at')->nullable();
                $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('done_note', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agency_list_uploads', 'done_at')) {
            Schema::table('agency_list_uploads', function (Blueprint $table) {
                $table->dropConstrainedForeignId('done_by');
                $table->dropColumn(['done_at', 'done_note']);
            });
        }
        if (Schema::hasColumn('applications', 'agency_submission_id')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->dropConstrainedForeignId('agency_submission_id');
            });
        }
        Schema::dropIfExists('agency_submissions');
    }
};
