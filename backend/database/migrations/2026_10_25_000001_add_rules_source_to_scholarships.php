<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table) {
            $table->text('rules_source')->nullable();
            $table->foreignId('rules_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rules_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scholarships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rules_updated_by');
            $table->dropColumn(['rules_source', 'rules_updated_at']);
        });
    }
};
