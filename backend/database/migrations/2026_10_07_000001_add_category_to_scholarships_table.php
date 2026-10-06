<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Program type, used by the student filter on the Scholarships page:
| government, csu (CSU-funded), lgu, private. Nullable so older rows still work.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table) {
            $table->string('category', 20)->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('scholarships', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
