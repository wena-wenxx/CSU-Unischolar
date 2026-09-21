<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('application_id')
                ->constrained('applications')
                ->cascadeOnDelete();

            $table->foreignId('scholarship_requirement_id')
                ->nullable()
                ->constrained('scholarship_requirements')
                ->nullOnDelete();

            $table->string('original_filename');
            $table->string('file_path');

            $table->string('document_type')->nullable();

            $table->enum('status', [
                'uploaded',
                'processing',
                'validated',
                'flagged',
                'needs_review'
            ])->default('uploaded');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};