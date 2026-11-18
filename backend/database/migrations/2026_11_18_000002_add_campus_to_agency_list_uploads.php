<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_list_uploads', function (Blueprint $table) {
            $table->string('campus', 255)->nullable()->after('agency_name');
        });
    }

    public function down(): void
    {
        Schema::table('agency_list_uploads', function (Blueprint $table) {
            $table->dropColumn('campus');
        });
    }
};
