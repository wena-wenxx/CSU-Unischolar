<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Processing batches
|--------------------------------------------------------------------------
| One processing cycle of one program, e.g. "TES - 1st Semester AY 2026-2027".
| Applications, approved lists, scholar records and payroll entries of that
| cycle point to it, so a new cycle never mixes with an old one. A completed
| batch stays for history; nothing in it is deleted.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scholarship_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('school_year', 9)->nullable();        // 2026-2027
            $table->string('semester', 30)->nullable();          // 1st Semester / 2nd Semester / Summer
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
