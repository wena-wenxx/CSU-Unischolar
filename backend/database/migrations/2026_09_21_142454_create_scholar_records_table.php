<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scholar_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();

            $table->foreignId('scholarship_id')
                ->constrained('scholarships')
                ->cascadeOnDelete();

            $table->enum('status', [
                'active',
                'inactive',
                'completed'
            ])->default('active');

            $table->boolean('currently_enrolled')->default(false);
            $table->boolean('has_atm')->default(false);

            $table->timestamp('grantee_tagged_at')->nullable();

            $table->text('remarks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholar_records');
    }
};