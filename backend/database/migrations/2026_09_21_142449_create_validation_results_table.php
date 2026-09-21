<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validation_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')
                ->unique()
                ->constrained('documents')
                ->cascadeOnDelete();

            $table->boolean('is_complete')->default(false);
            $table->boolean('has_name_mismatch')->default(false);
            $table->boolean('has_missing_information')->default(false);
            $table->boolean('has_wrong_document')->default(false);

            $table->decimal('confidence_score', 5, 2)->nullable();

            $table->text('extracted_text')->nullable();
            $table->json('extracted_data')->nullable();

            $table->text('flags')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_results');
    }
};