<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('enrollment_verified')
                ->default(false)
                ->after('submitted_at');

            $table->timestamp('enrollment_verified_at')
                ->nullable()
                ->after('enrollment_verified');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn([
                'enrollment_verified',
                'enrollment_verified_at',
            ]);
        });
    }
};
