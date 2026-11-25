<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

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
