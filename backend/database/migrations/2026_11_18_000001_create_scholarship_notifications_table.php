<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scholarship_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('scholarship_id')->constrained('scholarships')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ai_recommendation_id')->nullable()->constrained('ai_recommendations')->nullOnDelete();
            $table->string('type', 40)->default('scholarship_opportunity');
            $table->text('message');
            $table->timestamp('sent_at');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'scholarship_id', 'type']);
            $table->index(['scholarship_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarship_notifications');
    }
};
