<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Third role: admin (manages accounts and settings; does not process
| applications). Plus account status:
|   is_active             false = deactivated, cannot log in (history kept)
|   must_change_password  true after an admin sets a temporary password
|   last_login_at         shown in Manage Staff / Manage Students
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['student', 'staff', 'admin'])->default('student')->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role');
            $table->boolean('must_change_password')->default(false)->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'must_change_password', 'last_login_at']);
        });

        // Admin accounts cannot exist with the old two-role column.
        \Illuminate\Support\Facades\DB::table('users')->where('role', 'admin')->update(['role' => 'staff']);

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['student', 'staff'])->default('student')->change();
        });
    }
};
