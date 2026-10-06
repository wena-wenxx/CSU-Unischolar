<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A student asks OAS to correct registrar-owned information
| (name, student ID, course, year level, college). Students cannot edit
| those fields themselves; staff mark the request resolved after checking.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('field', 30);
            $table->string('requested_value');
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'resolved'])->default('pending');
            $table->text('staff_remarks')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_change_requests');
    }
};
