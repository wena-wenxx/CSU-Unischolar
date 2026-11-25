<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            $table->string('level', 10);
            $table->decimal('similarity', 5, 3)->default(0);
            $table->json('fields')->nullable();
            $table->json('words')->nullable();
            $table->string('method', 20);
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
