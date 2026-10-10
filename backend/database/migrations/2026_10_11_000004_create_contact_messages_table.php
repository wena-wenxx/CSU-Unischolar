<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Contact OAS": a student sends a message, OAS staff reply.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('topic', 40);              // application, documents, payroll, account, other
            $table->string('subject', 150);
            $table->text('message');
            $table->string('status', 20)->default('open')->index();   // open, answered, closed
            $table->text('reply')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('reply_read_at')->nullable();   // when the student saw the reply
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
