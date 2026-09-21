<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();

            $table->foreignId('scholarship_id')
                ->constrained('scholarships')
                ->cascadeOnDelete();

            $table->enum('status', [
                'draft',
                'submitted',
                'under_review',
                'needs_action',
                'complete',
                'approved',
                'rejected'
            ])->default('draft');

            $table->text('remarks')->nullable();

            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();

            $table->unique([
                'student_id',
                'scholarship_id'
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};