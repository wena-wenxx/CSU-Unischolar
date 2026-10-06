<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| One row every time an application moves to a new step
| (draft -> submitted -> under review -> ... ) or a milestone happens
| (enrollment verified, tagged as grantee).
|
| Used for: the student's timeline, the student's notifications
| (read_at = when the student saw it), and "last activity" on the staff side.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);          // a status, or enrollment_verified / grantee_tagged
            $table->text('remarks')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('read_at')->nullable(); // seen by the student
            $table->timestamps();

            $table->index(['application_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_status_logs');
    }
};
