<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->foreignId('processing_batch_id')->nullable()->after('school_year')
                ->constrained('processing_batches')->nullOnDelete();
            $table->string('semester', 30)->nullable()->after('school_year');
            $table->string('enrollment_method', 20)->nullable()->after('enrollment_verified_at');
        });

        Schema::table('agency_list_uploads', function (Blueprint $table) {
            $table->foreignId('processing_batch_id')->nullable()->after('scholarship_id')
                ->constrained('processing_batches')->nullOnDelete();
            $table->string('file_sha256', 64)->nullable()->after('file_name')->index();
        });

        Schema::table('payroll_records', function (Blueprint $table) {
            $table->foreignId('processing_batch_id')->nullable()->after('scholar_record_id')
                ->constrained('processing_batches')->nullOnDelete();
        });

        Schema::table('students', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('user_id')->index();
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        $programs = DB::table('scholarships')->get(['id', 'name', 'short_name'])->keyBy('id');
        $pairs = DB::table('applications')->whereNotNull('school_year')
            ->select('scholarship_id', 'school_year')->distinct()->get()
            ->merge(DB::table('agency_list_uploads')->whereNotNull('school_year')
                ->select('scholarship_id', 'school_year')->distinct()->get())
            ->unique(fn ($p) => $p->scholarship_id.'|'.$p->school_year);

        foreach ($pairs as $pair) {
            $program = $programs[$pair->scholarship_id] ?? null;
            if (!$program) {
                continue;
            }
            $label = $program->short_name ?: $program->name;
            $batchId = DB::table('processing_batches')->insertGetId([
                'scholarship_id' => $pair->scholarship_id,
                'name' => mb_substr("{$label} - AY {$pair->school_year} (before batches)", 0, 150),
                'school_year' => $pair->school_year,
                'semester' => null,
                'status' => 'open',
                'notes' => 'Created automatically for the records made before processing batches existed.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('applications')->where('scholarship_id', $pair->scholarship_id)
                ->where('school_year', $pair->school_year)->update(['processing_batch_id' => $batchId]);
            DB::table('agency_list_uploads')->where('scholarship_id', $pair->scholarship_id)
                ->where('school_year', $pair->school_year)->update(['processing_batch_id' => $batchId]);
        }

        $demoUsers = DB::table('users')->where('role', 'student')->get(['id', 'email'])
            ->filter(fn ($u) => preg_match('/^student\d+@carsu\.edu\.ph$/i', $u->email)
                || str_ends_with(strtolower($u->email), '@demo.carsu.edu.ph'))
            ->pluck('id');
        foreach ($demoUsers->chunk(500) as $chunk) {
            DB::table('students')->whereIn('user_id', $chunk->all())->update(['is_demo' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('processing_batch_id');
        });
        Schema::table('agency_list_uploads', function (Blueprint $table) {
            $table->dropIndex(['file_sha256']);
            $table->dropColumn('file_sha256');
            $table->dropConstrainedForeignId('processing_batch_id');
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('processing_batch_id');
            $table->dropColumn(['semester', 'enrollment_method']);
        });
    }
};
