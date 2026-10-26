<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index(['role', 'is_active']);
            $table->index('must_change_password');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['users_role_is_active_index']);
            $table->dropIndex(['users_must_change_password_index']);
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['activity_logs_action_created_at_index']);
        });
    }
};
