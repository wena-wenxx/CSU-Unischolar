<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('scholarship_id')->constrained('scholarships')->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->default(0);
            $table->unsignedSmallInteger('met_count')->default(0);
            $table->unsignedSmallInteger('total_count')->default(0);
            $table->string('verdict', 20)->default('no_data')->index();
            $table->json('checks')->nullable();
            $table->string('calculation')->nullable();
            $table->string('applied_status', 20)->nullable();
            $table->boolean('data_verified')->default(false);
            $table->string('staff_status', 20)->default('new')->index();
            $table->text('staff_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'scholarship_id']);
            $table->index(['scholarship_id', 'score']);
        });

        Schema::create('recommendation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_recommendation_id')->nullable()->constrained('ai_recommendations')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('scholarship_id')->nullable()->constrained('scholarships')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30)->index();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('verdict', 20)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->foreignId('scholarship_id')->nullable()->after('application_id')
                ->constrained('scholarships')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scholarship_id');
        });

        Schema::dropIfExists('recommendation_logs');
        Schema::dropIfExists('ai_recommendations');
    }
};
