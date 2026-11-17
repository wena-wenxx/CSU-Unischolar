<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_list_uploads', function (Blueprint $table) {
            $table->string('school_year', 9)->nullable()->after('agency_name');
            $table->string('semester', 30)->nullable()->after('school_year');
            $table->string('batch', 100)->nullable()->after('semester');
            $table->string('reference_number')->nullable()->after('batch');
            $table->date('list_date')->nullable()->after('reference_number');
            $table->unsignedInteger('expected_count')->nullable()->after('list_date');
            $table->decimal('expected_total', 14, 2)->nullable()->after('expected_count');
            $table->text('notes')->nullable()->after('expected_total');
        });
    }

    public function down(): void
    {
        Schema::table('agency_list_uploads', function (Blueprint $table) {
            $table->dropColumn([
                'school_year', 'semester', 'batch', 'reference_number', 'list_date',
                'expected_count', 'expected_total', 'notes',
            ]);
        });
    }
};
