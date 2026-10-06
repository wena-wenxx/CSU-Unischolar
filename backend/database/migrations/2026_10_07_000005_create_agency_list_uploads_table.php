<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Log of every "approved list" file OAS staff uploaded from an external
| agency (CHED, DOST, LGU, foundation): who, when, for which program,
| and what happened to each row.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_list_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scholarship_id')->constrained('scholarships')->cascadeOnDelete();
            $table->string('agency_name');
            $table->string('file_name');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('approved_count')->default(0);   // existing application -> approved
            $table->unsignedInteger('created_count')->default(0);    // no application yet -> approved application created
            $table->unsignedInteger('already_count')->default(0);    // already approved, nothing changed
            $table->unsignedInteger('unmatched_count')->default(0);  // student ID not in the system
            $table->unsignedInteger('error_count')->default(0);      // matched but cannot be approved (see details)
            $table->json('details')->nullable();                     // one line per row
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_list_uploads');
    }
};
