<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('scholar_record_id')
                ->constrained('scholar_records')
                ->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('period');
            $table->string('bank_atm_status')->default('No');

            $table->enum('status', [
                'draft',
                'ready',
                'processed'
            ])->default('draft');

            $table->string('signature')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_records');
    }
};