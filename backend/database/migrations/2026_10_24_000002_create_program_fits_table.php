<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Round 12: AI field fit.
|   students.interests   optional "My field and goals" (typed by the student)
|   program_fits         how well each active scholarship fits each student's
|                        field (from the AI service: embeddings or TF-IDF).
|                        It only ranks and explains; eligibility stays with the rules.
| Also: the demo agency-direct programs get a "who it is for" sentence taken
| from the agencies' own pages (only where the description is still the
| generic demo note), so the AI has something to match.
*/
return new class extends Migration
{
    private const NOTE = 'Apply directly to the scholarship-giving agency; requirements and allowances are handled by its office. The OAS posts announcements and updates when the agency requests it.';

    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('interests', 500)->nullable()->after('family_income');
        });

        Schema::create('program_fits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scholarship_id')->constrained()->cascadeOnDelete();
            $table->string('level', 10);                 // high | medium | low | any
            $table->decimal('similarity', 5, 3)->default(0);
            $table->json('fields')->nullable();          // shared fields, e.g. ["Agriculture"]
            $table->json('words')->nullable();           // shared words, for the explanation
            $table->string('method', 20);                // embeddings | tfidf
            $table->timestamp('computed_at')->nullable();
            $table->unique(['student_id', 'scholarship_id']);
        });

        foreach (\Database\Seeders\DemoDataSeeder::FOCUS as $name => $focus) {
            DB::table('scholarships')->where('name', $name)->where('description', self::NOTE)
                ->update(['description' => $focus.' '.self::NOTE]);
        }
    }

    public function down(): void
    {
        foreach (\Database\Seeders\DemoDataSeeder::FOCUS as $name => $focus) {
            DB::table('scholarships')->where('name', $name)->where('description', $focus.' '.self::NOTE)
                ->update(['description' => self::NOTE]);
        }
        Schema::dropIfExists('program_fits');
        Schema::table('students', fn (Blueprint $table) => $table->dropColumn('interests'));
    }
};
