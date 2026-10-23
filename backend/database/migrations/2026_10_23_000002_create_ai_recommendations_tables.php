<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| AI Recommendations (rule-based, see app/Support/EligibilityEngine.php).
|
| ai_recommendations: ONE row per student per scholarship, recalculated when
| staff press "Recalculate" (or run: php artisan recommendations:run).
|   score            0-100 = met_count / total_count x 100, equal weights
|   verdict          eligible      every must-have rule is met
|                    not_eligible  at least one must-have rule is NOT met
|                    no_data       a must-have rule could not be checked
|                                  (e.g. the student has not entered a GWA)
|   checks           every check, with the rule, the student's value used,
|                    and the result (met / not_met / no_data) -> shown to staff
|   calculation      the exact sum, e.g. "7 of 10 checks met = 70%"
|   staff_status     new | invited | added | dismissed | wrong
|                    (what OAS staff decided; the AI never decides)
|
| recommendation_logs: every recommendation SHOWN to staff and every staff
| DECISION (invited, added, dismissed, marked wrong, restored), with the score
| at that moment, so the recommendations can be audited later.
|
| email_logs.scholarship_id: for invitation e-mails (no application yet).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('scholarship_id')->constrained('scholarships')->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->default(0);
            $table->unsignedSmallInteger('met_count')->default(0);
            $table->unsignedSmallInteger('total_count')->default(0);
            $table->string('verdict', 20)->default('no_data')->index();
            $table->json('checks')->nullable();
            $table->string('calculation')->nullable();
            $table->string('applied_status', 20)->nullable();     // the student's application status, if any
            $table->boolean('data_verified')->default(false);      // GWA / income checked by OAS
            $table->string('staff_status', 20)->default('new')->index();
            $table->text('staff_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'scholarship_id']);
            $table->index(['scholarship_id', 'score']);
        });

        Schema::create('recommendation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_recommendation_id')->nullable()->constrained('ai_recommendations')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('scholarship_id')->nullable()->constrained('scholarships')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30)->index();   // shown, invited, added, dismissed, wrong, restored, recalculated
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('verdict', 20)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->foreignId('scholarship_id')->nullable()->after('application_id')
                ->constrained('scholarships')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scholarship_id');
        });

        Schema::dropIfExists('recommendation_logs');
        Schema::dropIfExists('ai_recommendations');
    }
};
