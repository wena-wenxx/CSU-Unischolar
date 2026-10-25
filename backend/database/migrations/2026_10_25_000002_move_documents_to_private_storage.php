<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/*
| Privacy: moves student documents saved by older versions out of the public
| folder (see app/Console/Commands/MakeDocumentsPrivate.php). Nothing in the
| database changes; the files keep the same names.
*/
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('documents:make-private');
    }

    public function down(): void
    {
        // Not moved back: documents should stay private.
    }
};
