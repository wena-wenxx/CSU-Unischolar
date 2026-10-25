<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/*
| php artisan documents:make-private
| Moves student documents saved by older versions from the PUBLIC folder
| (storage/app/public/documents, reachable by link without logging in) to
| the PRIVATE folder (storage/app/private/documents). Safe to run more than
| once. The 2026_10_25_000002 migration runs it automatically.
*/
class MakeDocumentsPrivate extends Command
{
    protected $signature = 'documents:make-private';

    protected $description = 'Move student documents from the public folder to private storage';

    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk(Document::DISK);
        $moved = 0;

        foreach ($public->allFiles('documents') as $path) {
            if (!$private->exists($path)) {
                $private->put($path, $public->get($path));
            }
            $public->delete($path);
            $moved++;
        }

        $this->info($moved ? "Moved {$moved} document file(s) to private storage." : 'No documents in the public folder. Nothing to move.');

        return self::SUCCESS;
    }
}
