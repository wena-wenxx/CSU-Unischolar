<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Optional picture for an announcement.
|   "announcements/abc.jpg"  uploaded by staff (Laravel public storage)
|   "/announcements/x.jpg"   a sample picture shipped with the frontend
| Empty = the page shows a CSU placeholder.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
