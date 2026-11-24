<?php

namespace App\Jobs;

use App\Models\Document;
use App\Support\DocumentChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/*
| Runs one AI document check in the AI worker window
|     php artisan queue:work database --queue=ai --timeout=300 --tries=1
| so the website stays fast while the AI reads the file.
*/
class ValidateDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 290;

    public function __construct(public int $documentId)
    {
    }

    public function handle(): void
    {
        $document = Document::find($this->documentId);
        if (!$document || $document->status !== 'processing' || DocumentChecker::wasCancelled($document->id)) {
            return;   // deleted, cancelled, or another check already finished it
        }

        DocumentChecker::markWorkerBusy(true);
        try {
            DocumentChecker::run($document, DocumentChecker::WORKER_TIMEOUT);
        } finally {
            DocumentChecker::markWorkerBusy(false);
        }
    }

    public function failed(?\Throwable $e): void
    {
        $document = Document::find($this->documentId);
        if ($document && $document->status === 'processing') {
            // Puts the document back so the button can be pressed again, with a message for the page.
            DocumentChecker::abandon($document, 'The AI check stopped before it finished'
                .($e && str_contains(get_class($e), 'Timeout') ? ' (it took too long)' : '').'. Press Run AI check again.');
        }
    }
}
