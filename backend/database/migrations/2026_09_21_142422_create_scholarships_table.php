<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scholarships', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('provider')->nullable();

            $table->date('application_start')->nullable();
            $table->date('application_end')->nullable();

            $table->decimal('amount', 12, 2)->nullable();

            $table->enum('status', [
                'active',
                'inactive',
                'closed'
            ])->default('active');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarships');
    }
};