<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Where a program's eligibility rules come from (memo / CMO number and the
| sentences used), and who last changed the rules and when. Shown to staff
| in the "Why?" window so every score can be traced back to its source.
*/
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
