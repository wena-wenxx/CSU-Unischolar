<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Who did what and when (shown to the admin in Activity Logs).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();   // kept even if the account changes
            $table->string('role', 20)->nullable();
            $table->string('action', 60)->index();     // e.g. application.approved, account.created
            $table->text('description');
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
