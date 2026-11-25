<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scholarship_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('school_year', 9)->nullable();
            $table->string('semester', 30)->nullable();
            $table->enum('status', ['open', 'completed'])->default('open');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['scholarship_id', 'name'], 'processing_batches_one_name_per_program');
            $table->index(['scholarship_id', 'school_year', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_batches');
    }
};
