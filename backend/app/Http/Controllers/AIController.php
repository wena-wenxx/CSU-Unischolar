<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Jobs\ValidateDocumentJob;
use App\Support\DocumentChecker;
use Illuminate\Http\Request;

/**
 * Sends an uploaded document to the Python AI service (FastAPI + PaddleOCR)
 * and saves what comes back. The AI only FLAGS possible problems; OAS staff
 * always make the final decision.
 */
class AIController extends Controller
{
    public function validateDocument(Request $request, $documentId)
    {
        $document = Document::with([
            'application.student',
            'student',
            'requirement'
        ])->findOrFail($documentId);

        // Staff can check any document. A student can check their OWN file
        // while it is still theirs to change: saved in My Documents, or in a
        // draft / needs-action application. (The AI only flags; nothing is
        // approved or rejected automatically.)
        if ($request->user()->role !== 'staff') {
            $mine = $document->student_id
                && $request->user()->student
                && $document->student_id === $request->user()->student->id;
            $editable = !$document->application_id
                || in_array($document->application?->status, ['draft', 'needs_action'], true);

            if (!$mine || !$editable) {
                return response()->json([
                    'message' => 'You can only check your own documents before they are submitted.'
                ], 403);
            }
        }

        DocumentChecker::expireIfStale($document);

        // Verified by a staff member: the AI check is not needed any more. Staff can still run it on purpose
        // (confirm = true); the staff decision stays either way (it is saved separately from the AI result).
        if ($document->staff_verified_at && !$request->boolean('confirm')) {
            return response()->json([
                'message' => 'This document was already verified by OAS staff'.($document->staffVerifier?->name ? " ({$document->staffVerifier->name})" : '')
                    .' on '.$document->staff_verified_at->copy()->timezone('Asia/Manila')->format('M j, Y').'. The AI check is not needed. Run it anyway?',
                'needs_confirmation' => true,
                'staff_verified' => true,
            ], 409);
        }

        // Already being checked: do not start a second check of the same file.
        if (DocumentChecker::isRunning($document)) {
            return response()->json([
                'message' => 'The AI check of this document is already running. The result will appear here when it is ready.',
                'queued' => true,
                'document' => $document->fresh()->load('validationResult'),
            ], 202);
        }

        $fullPath = $document->absolutePath();
        if (!$fullPath || !file_exists($fullPath)) {
            return response()->json(['message' => 'Document file not found.'], 404);
        }

        DocumentChecker::begin($document);

        // AI worker running: the check is done there and this request returns at once,
        // so the rest of the website does not wait for the AI.
        if (DocumentChecker::workerRunning()) {
            ValidateDocumentJob::dispatch($document->id)->onConnection('database')->onQueue('ai');

            return response()->json([
                'message' => 'AI check started. The result will appear here when it is ready.',
                'queued' => true,
                'document' => $document->fresh()->load('validationResult'),
            ], 202);
        }

        // No worker: check inside this request, with a time limit below PHP's own limit.
        $outcome = DocumentChecker::run($document, DocumentChecker::inlineTimeout());

        return response()->json([
            'message' => $outcome['message'],
            'queued' => false,
            'error' => !$outcome['ok'] && $request->user()->role === 'staff' ? ($outcome['detail'] ?? null) : null,
            'document' => $document->fresh()->load('validationResult'),
        ], $outcome['http']);
    }

    /*
    | GET /documents/{documentId}/check-status
    | The page asks this every few seconds while a check runs in the AI worker.
    | running = still checking; done = finished (see document.status and validation_result);
    | failed = it stopped, with the reason in "message".
    */
    /*
    | POST /documents/{documentId}/cancel-check
    | "Cancel" on a running AI check (the student who owns the file, or staff). Nothing is saved.
    */
    public function cancelCheck(Request $request, $documentId)
    {
        $document = Document::findOrFail($documentId);
        if ($request->user()->role !== 'staff') {
            $mine = $document->student_id && $request->user()->student && $document->student_id === $request->user()->student->id;
            if (!$mine) {
                return response()->json(['message' => 'Not your document.'], 403);
            }
        }

        $cancelled = DocumentChecker::cancel($document);

        return response()->json([
            'cancelled' => $cancelled,
            'message' => $cancelled
                ? 'AI check cancelled. Nothing was saved; you can run it again at any time.'
                : 'The AI check had already finished, so there was nothing to cancel.',
            'document' => $document->fresh()->load('validationResult'),
        ]);
    }

    public function checkStatus(Request $request, $documentId)
    {
        $document = Document::with(['application', 'validationResult'])->findOrFail($documentId);

        if ($request->user()->role !== 'staff') {
            $mine = $document->student_id
                && $request->user()->student
                && $document->student_id === $request->user()->student->id;
            if (!$mine) {
                return response()->json(['message' => 'Not your document.'], 403);
            }
        }

        DocumentChecker::expireIfStale($document);
        $document->refresh()->load('validationResult');
        $running = DocumentChecker::isRunning($document);
        $error = $running ? null : DocumentChecker::lastError($document->id);

        return response()->json([
            'state' => $running ? 'running' : ($error ? 'failed' : 'done'),
            'message' => $error,
            'worker' => DocumentChecker::workerRunning(),
            'document' => $document,
        ]);
    }

    /*
    | POST /documents/{documentId}/verify   (OAS staff)
    | Body: verified (true | false), note (how it was checked, optional)
    | A person's confirmation that the document is genuine, e.g. the PhilID
    | QR was checked in PhilSys Check or the barangay confirmed the clearance.
    | The AI never does this; it only flags.
    */
    public function verify(Request $request, $documentId)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json(['message' => 'Only OAS staff can verify documents.'], 403);
        }

        $data = $request->validate([
            'verified' => 'required|boolean',
            'note' => 'nullable|string|max:255',
        ]);

        $document = Document::with('student')->findOrFail($documentId);
        $document->update([
            'staff_verified_at' => $data['verified'] ? now() : null,
            'staff_verified_by' => $data['verified'] ? $request->user()->id : null,
            'staff_verify_note' => $data['verified'] ? ($data['note'] ?? null) : null,
        ]);

        if ($document->student_id) {
            \App\Support\EligibilityEngine::recalculate([$document->student_id]);
        }
        \App\Models\ActivityLog::record($request->user(), $data['verified'] ? 'document.verified' : 'document.unverified',
            ($data['verified'] ? 'Verified' : 'Removed the verification of')." the {$document->typeName()} of "
            .($document->student ? trim($document->student->first_name.' '.$document->student->last_name) : 'a student')
            .(!empty($data['note']) ? " ({$data['note']})" : '').'.', $document);

        return response()->json([
            'message' => $data['verified'] ? 'Marked as verified by staff.' : 'Verification removed.',
            'document' => $document->fresh(['validationResult', 'staffVerifier:id,name']),
        ]);
    }
}
