<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| "My Documents": a document now belongs to a STUDENT, not only to one
| application.
|  - student_id: who owns the file (filled in for existing rows below).
|  - application_id becomes optional: a document saved in My Documents
|    before applying has no application yet.
| When a saved document is reused for another application, a new row is
| made for that application that points to the same stored file.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('student_id')->nullable()->after('id')->constrained('students')->cascadeOnDelete();
        });

        // MySQL does not let us change a column while a foreign key uses it,
        // so: drop the key, make the column optional, add the key back.
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('application_id')->nullable()->change();
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
        });

        DB::statement('UPDATE documents SET student_id = (SELECT applications.student_id FROM applications WHERE applications.id = documents.application_id) WHERE student_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('student_id');
        });
    }
};
