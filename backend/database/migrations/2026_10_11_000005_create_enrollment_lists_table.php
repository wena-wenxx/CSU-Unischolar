<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The Registrar's list of enrolled students for a term, uploaded by OAS as a
| CSV file. "Verify All Enrollments" matches approved applicants and active
| grantees against it by Student ID. (No live connection to the Registrar.)
| forwarded_at: when OAS forwarded the application to the agency.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_lists', function (Blueprint $table) {
            $table->id();
            $table->string('period', 100);
            $table->string('file_name');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('rows_count')->default(0);
            $table->timestamps();
        });

        Schema::create('enrollment_list_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_list_id')->constrained('enrollment_lists')->cascadeOnDelete();
            $table->string('student_number', 50)->index();
            $table->string('last_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('course')->nullable();
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->timestamp('forwarded_at')->nullable()->after('submitted_at');
        });

        \App\Models\Application::backfillForwardedAt();
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('forwarded_at');
        });
        Schema::dropIfExists('enrollment_list_entries');
        Schema::dropIfExists('enrollment_lists');
    }
};
