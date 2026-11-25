<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table) {
            $table->decimal('max_gwa', 4, 2)->nullable()->after('amount');
            $table->decimal('max_family_income', 12, 2)->nullable()->after('max_gwa');
            $table->json('year_levels')->nullable()->after('max_family_income');
            $table->json('colleges')->nullable()->after('year_levels');
            $table->boolean('one_scholarship_only')->default(true)->after('colleges');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->decimal('gwa', 4, 2)->nullable()->after('contact_number');
            $table->decimal('family_income', 12, 2)->nullable()->after('gwa');
            $table->timestamp('eligibility_updated_at')->nullable()->after('family_income');
            $table->timestamp('eligibility_verified_at')->nullable()->after('eligibility_updated_at');
            $table->foreignId('eligibility_verified_by')->nullable()->after('eligibility_verified_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('eligibility_verified_by');
            $table->dropColumn(['gwa', 'family_income', 'eligibility_updated_at', 'eligibility_verified_at']);
        });

        Schema::table('scholarships', function (Blueprint $table) {
            $table->dropColumn(['max_gwa', 'max_family_income', 'year_levels', 'colleges', 'one_scholarship_only']);
        });
    }
};
