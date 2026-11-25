<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('staff_verified_at')->nullable()->after('status');
            $table->foreignId('staff_verified_by')->nullable()->after('staff_verified_at')->constrained('users')->nullOnDelete();
            $table->string('staff_verify_note', 255)->nullable()->after('staff_verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_verified_by');
            $table->dropColumn(['staff_verified_at', 'staff_verify_note']);
        });
    }
};
