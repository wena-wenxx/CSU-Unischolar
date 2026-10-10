<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| short_name        "CMSP", "TES", "SA": shown on the program tabs.
| application_mode  oas            students apply in ScholarGuide; OAS processes
|                   agency_direct  students apply directly at the agency; OAS
|                                  only posts announcements and may record the
|                                  agency's list of grantees (Approved Lists).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table) {
            $table->string('short_name', 40)->nullable()->after('name');
            $table->string('application_mode', 20)->default('oas')->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('scholarships', function (Blueprint $table) {
            $table->dropColumn(['short_name', 'application_mode']);
        });
    }
};
