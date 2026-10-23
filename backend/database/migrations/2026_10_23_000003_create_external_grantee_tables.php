<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| External Grantees: students who already hold a scholarship from an OUTSIDE
| party (LGU, CHED list, private foundation) that is not processed in
| ScholarGuide.
|
| external_grantee_uploads: one row per uploaded file (PDF, Word, Excel, CSV).
|   status   review     the AI read the file; staff still have to confirm
|            saved      staff confirmed the matches -> external_grantees rows
|            discarded  staff threw the upload away
|   rows     what the AI read from the file, with its match suggestions
|            (kept so the extracted records can be exported later)
|
| external_grantees: one row per line of a SAVED upload.
|   match_status  matched    linked to a student in the system
|                 unmatched  no such student (kept for the record)
|                 error      the line could not be read (no name and no ID)
|   match_method  student_id | name | manual (picked by staff)
|   status        active | ended   (an ended holding no longer counts for
|                                   the one-active-scholarship rule)
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_grantee_uploads', function (Blueprint $table) {
            $table->id();
            $table->string('program_name');
            $table->string('agency')->nullable();
            $table->string('category', 20)->nullable();         // lgu, ched, government, private, other
            $table->string('school_year', 20)->nullable();      // 2026-2027
            $table->string('file_name');
            $table->string('file_path')->nullable();            // private copy of the uploaded file
            $table->string('file_type', 10)->nullable();        // pdf, docx, xlsx, csv, png, jpg
            $table->string('status', 20)->default('review')->index();
            $table->string('read_method', 30)->nullable();      // table, text, ocr
            $table->json('warnings')->nullable();
            $table->json('rows')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('check_count')->default(0);
            $table->unsignedInteger('unmatched_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('saved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('saved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('external_grantees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_id')->nullable()->constrained('external_grantee_uploads')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('extracted_name')->nullable();
            $table->string('extracted_student_id', 50)->nullable();
            $table->string('program_name');
            $table->string('agency')->nullable();
            $table->string('category', 20)->nullable();
            $table->string('school_year', 20)->nullable();
            $table->string('match_status', 20)->index();        // matched, unmatched, error
            $table->string('match_method', 20)->nullable();     // student_id, name, manual
            $table->unsignedTinyInteger('match_score')->nullable();
            $table->string('status', 20)->default('active');    // active, ended
            $table->timestamp('ended_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index('program_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_grantees');
        Schema::dropIfExists('external_grantee_uploads');
    }
};
