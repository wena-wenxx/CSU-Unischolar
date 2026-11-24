<?php

namespace App\Support;

use App\Models\Document;
use App\Models\ValidationResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| AI document check (shared by the web request and the background worker)
|--------------------------------------------------------------------------
| Sends one uploaded document to the Python AI service and saves the result.
| The AI only FLAGS possible problems; OAS staff always decide.
|
| Why there are two ways to run it
| ---------------------------------
| "php artisan serve" on Windows answers ONE request at a time. A check that
| takes 1-3 minutes therefore froze every other page. When the AI worker runs
|     php artisan queue:work database --queue=ai --timeout=300 --tries=1
| the check is done in that separate window and the web request returns at
| once (the page asks for the result every few seconds). Without the worker,
| the check runs inside the request, but with a time limit below PHP's own
| limit, so the document can never stay on "processing" forever.
*/
class DocumentChecker
{
    /** A check still "processing" after this many minutes did not finish (worker closed, PHP stopped). */
    public const STALE_MINUTES = 6;

    /** Seconds the background worker waits for the AI service. */
    public const WORKER_TIMEOUT = 240;

    public const WORKER_KEY = 'ai_worker_seen_at';

    /** True when "php artisan queue:work database --queue=ai" was seen in the last 30 seconds. */
    public static function workerRunning(): bool
    {
        $seen = (int) Cache::get(self::WORKER_KEY, 0);
        // While the worker is busy with a long check it does not loop, so "busy until" counts too.
        $busyUntil = (int) Cache::get(self::WORKER_KEY.'_busy_until', 0);

        return ($seen > 0 && $seen >= now()->subSeconds(30)->timestamp) || $busyUntil >= now()->timestamp;
    }

    public static function markWorkerAlive(): void
    {
        Cache::put(self::WORKER_KEY, now()->timestamp, 120);
    }

    public static function markWorkerBusy(bool $busy): void
    {
        if ($busy) {
            Cache::put(self::WORKER_KEY.'_busy_until', now()->addSeconds(self::WORKER_TIMEOUT + 30)->timestamp, self::WORKER_TIMEOUT + 60);
        } else {
            Cache::forget(self::WORKER_KEY.'_busy_until');
            self::markWorkerAlive();
        }
    }

    /** Seconds the in-request check may wait: below PHP's max_execution_time, so PHP never kills it midway. */
    public static function inlineTimeout(): int
    {
        $limit = (int) ini_get('max_execution_time');   // 0 = no limit (command line)
        if ($limit <= 0) {
            return 170;
        }

        return max(10, min(170, $limit - 12));
    }

    public static function isRunning(Document $document): bool
    {
        return $document->status === 'processing'
            && $document->updated_at
            && $document->updated_at->gt(now()->subMinutes(self::STALE_MINUTES));
    }

    /**
     * A document left on "processing" by a check that never finished goes back to the status it had
     * before, with a message, so the button can be pressed again.
     */
    public static function expireIfStale(Document $document): Document
    {
        if ($document->status === 'processing' && !self::isRunning($document)) {
            self::abandon($document, 'The last AI check did not finish (the AI service or the worker stopped). Press Run AI check again.');
        }

        return $document;
    }

    /** Ends a check that cannot finish: the document gets its old status back and the page shows the message. */
    public static function abandon(Document $document, string $message): void
    {
        $document->update(['status' => self::statusBefore($document)]);
        self::rememberError($document->id, $message);
    }

    /**
     * "Cancel" on a running AI check. The document goes back to the status it had before, at once.
     * A check waiting in the AI worker is skipped; one the AI service is already reading finishes there,
     * but its result is thrown away (nothing is saved). Returns false when no check was running.
     */
    public static function cancel(Document $document): bool
    {
        if ($document->status !== 'processing') {
            return false;
        }
        Cache::put("doc_check_cancel:{$document->id}", true, 900);
        Cache::forget("doc_check_error:{$document->id}");
        $document->update(['status' => self::statusBefore($document)]);

        return true;
    }

    public static function wasCancelled(int $documentId): bool
    {
        return (bool) Cache::get("doc_check_cancel:{$documentId}");
    }

    public static function begin(Document $document): void
    {
        Cache::forget("doc_check_cancel:{$document->id}");
        Cache::put("doc_check_before:{$document->id}", $document->status === 'processing' ? 'uploaded' : $document->status, 3600);
        Cache::forget("doc_check_error:{$document->id}");
        $document->update(['status' => 'processing']);
    }

    public static function lastError(int $documentId): ?string
    {
        return Cache::get("doc_check_error:{$documentId}");
    }

    private static function rememberError(int $documentId, string $message): void
    {
        Cache::put("doc_check_error:{$documentId}", $message, 86400);
    }

    private static function statusBefore(Document $document): string
    {
        $before = Cache::get("doc_check_before:{$document->id}");
        if ($before && $before !== 'processing') {
            return $before;
        }

        return $document->validationResult ? 'needs_review' : 'uploaded';
    }

    /**
     * Runs the check. Returns ['ok' => bool, 'status' => document status, 'message' => text, 'http' => code].
     * $timeout = seconds to wait for the AI service.
     */
    public static function run(Document $document, int $timeout): array
    {
        $document->loadMissing(['application.student', 'student', 'requirement', 'validationResult']);
        $fullPath = $document->absolutePath();

        if (!$fullPath || !file_exists($fullPath)) {
            $document->update(['status' => self::statusBefore($document)]);

            return self::failed($document, 'Document file not found.', 404);
        }

        $student = $document->student ?? $document->application?->student;
        if (!$student) {
            $document->update(['status' => self::statusBefore($document)]);

            return self::failed($document, 'This document is not linked to a student.', 422);
        }

        // First + last name only: validation.py already accepts a middle name printed on the document.
        $expectedName = trim($student->first_name.' '.$student->last_name);
        // The requirement name ("Certificate of Registration (COR)") tells the AI which kind of document to expect.
        $documentLabel = $document->requirement?->name ?? $document->document_type ?? '';

        $started = microtime(true);
        try {
            $response = Http::connectTimeout(3)
                ->timeout($timeout)
                ->attach('file', file_get_contents($fullPath), $document->original_filename)
                ->post(rtrim(config('services.ai.url'), '/').'/validate-document', [
                    'expected_name' => $expectedName,
                    'expected_student_id' => $student->student_id,
                    // Parents' documents (e.g. ITR) only show the surname.
                    'expected_last_name' => $student->last_name,
                    'document_label' => $documentLabel,
                    // A COR from another school year is flagged.
                    'current_school_year' => \App\Models\ExternalGrantee::currentSchoolYear(),
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $document->update(['status' => self::statusBefore($document)]);
            $tookLong = (microtime(true) - $started) >= $timeout - 1;

            return self::failed($document, $tookLong
                ? "The AI check took longer than {$timeout} seconds and was stopped. Start the AI worker (see the start script) so long checks run in the background, then press Run AI check again."
                : 'The AI service is not running (start it with: uvicorn main:app --port 8001). Nothing was changed; press Run AI check again when it is running.',
                $tookLong ? 504 : 503, $e->getMessage());
        } catch (\Throwable $e) {
            $document->update(['status' => self::statusBefore($document)]);

            return self::failed($document, 'The AI check could not be completed. Please try again.', 503, $e->getMessage());
        }

        if (!$response->successful()) {
            $document->update(['status' => self::statusBefore($document)]);

            return self::failed($document, 'The AI service returned an error. Nothing was changed; please try again or check the document manually.', 502,
                (string) $response->body());
        }

        // Cancelled by the user while the AI was reading: the result is not saved.
        if (self::wasCancelled($document->id)) {
            return ['ok' => false, 'status' => $document->fresh()->status, 'http' => 200, 'cancelled' => true,
                'message' => 'The AI check was cancelled. Nothing was saved.'];
        }

        $result = (array) $response->json();

        // An old certificate (e.g. an indigency issued two years ago) is flagged for staff.
        $outdated = DocumentAge::outdatedFlag(
            $document->typeName(),
            (array) ($result['extracted_data']['dates'] ?? [])
        );
        if ($outdated) {
            $result['flags'] = array_merge((array) ($result['flags'] ?? []), [$outdated]);
        }

        $validation = ValidationResult::updateOrCreate(
            ['document_id' => $document->id],
            [
                'is_complete' => (bool) ($result['is_complete'] ?? false),
                'has_name_mismatch' => (bool) ($result['has_name_mismatch'] ?? false),
                'has_missing_information' => (bool) ($result['has_missing_information'] ?? false),
                'has_wrong_document' => (bool) ($result['has_wrong_document'] ?? false),
                'confidence_score' => $result['confidence_score'] ?? null,
                'extracted_text' => $result['extracted_text'] ?? null,
                'extracted_data' => $result['extracted_data'] ?? null,
                'flags' => !empty($result['flags']) ? implode("\n", $result['flags']) : null,
            ]
        );

        // Almost nothing could be read (blank or very poor scan): a person must look at it, the same as
        // an unreadable file, instead of treating it as an ordinary flag.
        $tooLittleText = collect((array) ($result['flags'] ?? []))
            ->contains(fn ($flag) => str_starts_with((string) $flag, 'Very little text could be read'));

        if (($result['ocr_status'] ?? 'ok') !== 'ok' || $tooLittleText) {
            $status = 'needs_review';
        } elseif (
            $outdated ||
            !$validation->is_complete ||
            $validation->has_name_mismatch ||
            $validation->has_missing_information ||
            $validation->has_wrong_document
        ) {
            $status = 'flagged';
        } else {
            $status = 'validated';
        }

        $document->update(['status' => $status]);
        Cache::forget("doc_check_error:{$document->id}");

        return [
            'ok' => true,
            'status' => $status,
            'http' => 200,
            'message' => match ($status) {
                'validated' => 'AI check finished: no issues found.',
                'flagged' => 'AI check finished: possible issues flagged for your review.',
                default => $tooLittleText
                    ? 'Very little text could be read from this file (blank, unclear or low-quality scan). It was sent to manual review.'
                    : 'The file could not be read automatically. Please check it manually.',
            },
        ];
    }

    private static function failed(Document $document, string $message, int $http, ?string $detail = null): array
    {
        if (self::wasCancelled($document->id)) {   // cancelled by the user: no error to show
            return ['ok' => false, 'status' => $document->status, 'http' => 200, 'cancelled' => true, 'message' => 'The AI check was cancelled. Nothing was saved.'];
        }
        self::rememberError($document->id, $message);
        if ($detail) {
            \Illuminate\Support\Facades\Log::warning("AI check of document {$document->id} failed: {$detail}");
        }

        return ['ok' => false, 'status' => $document->status, 'http' => $http, 'message' => $message, 'detail' => $detail];
    }
}
