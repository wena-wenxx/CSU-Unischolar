<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_grantee_uploads', function (Blueprint $table) {
            $table->id();
            $table->string('program_name');
            $table->string('agency')->nullable();
            $table->string('category', 20)->nullable();
            $table->string('school_year', 20)->nullable();
            $table->string('file_name');
            $table->string('file_path')->nullable();
            $table->string('file_type', 10)->nullable();
            $table->string('status', 20)->default('review')->index();
            $table->string('read_method', 30)->nullable();
            $table->json('warnings')->nullable();
            $table->json('rows')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('check_count')->default(0);
            $table->unsignedInteger('unmatched_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('saved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('saved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('external_grantees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_id')->nullable()->constrained('external_grantee_uploads')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('extracted_name')->nullable();
            $table->string('extracted_student_id', 50)->nullable();
            $table->string('program_name');
            $table->string('agency')->nullable();
            $table->string('category', 20)->nullable();
            $table->string('school_year', 20)->nullable();
            $table->string('match_status', 20)->index();
            $table->string('match_method', 20)->nullable();
            $table->unsignedTinyInteger('match_score')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('ended_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index('program_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_grantees');
        Schema::dropIfExists('external_grantee_uploads');
    }
};
