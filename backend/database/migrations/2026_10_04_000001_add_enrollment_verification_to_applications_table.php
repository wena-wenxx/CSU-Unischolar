<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Enrollment verification lives on the APPLICATION, not the student.
|--------------------------------------------------------------------------
| OAS workflow: the agency approves -> OAS verifies the student is currently
| enrolled -> OAS tags the student as a grantee (scholar_records).
| Verification is recorded per approved application, so a student's
| history shows when each scholarship's enrollment check happened.
*/

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
