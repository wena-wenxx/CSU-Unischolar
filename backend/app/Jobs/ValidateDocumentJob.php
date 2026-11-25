<?php

namespace App\Jobs;

use App\Models\Document;
use App\Support\DocumentChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

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
            return;
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
            DocumentChecker::abandon($document, 'The AI check stopped before it finished'
                .($e && str_contains(get_class($e), 'Timeout') ? ' (it took too long)' : '').'. Press Run AI check again.');
        }
    }
}
