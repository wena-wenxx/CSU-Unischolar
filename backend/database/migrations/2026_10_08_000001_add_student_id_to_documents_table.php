<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('student_id')->nullable()->after('id')->constrained('students')->cascadeOnDelete();
        });

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
