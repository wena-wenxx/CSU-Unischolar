<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Jobs\ValidateDocumentJob;
use App\Support\DocumentChecker;
use Illuminate\Http\Request;

class AIController extends Controller
{
    public function validateDocument(Request $request, $documentId)
    {
        $document = Document::with([
            'application.student',
            'student',
            'requirement'
        ])->findOrFail($documentId);

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

        if ($document->staff_verified_at && !$request->boolean('confirm')) {
            return response()->json([
                'message' => 'This document was already verified by OAS staff'.($document->staffVerifier?->name ? " ({$document->staffVerifier->name})" : '')
                    .' on '.$document->staff_verified_at->copy()->timezone('Asia/Manila')->format('M j, Y').'. The AI check is not needed. Run it anyway?',
                'needs_confirmation' => true,
                'staff_verified' => true,
            ], 409);
        }

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

        if (DocumentChecker::workerRunning()) {
            ValidateDocumentJob::dispatch($document->id)->onConnection('database')->onQueue('ai');

            return response()->json([
                'message' => 'AI check started. The result will appear here when it is ready.',
                'queued' => true,
                'document' => $document->fresh()->load('validationResult'),
            ], 202);
        }

        $outcome = DocumentChecker::run($document, DocumentChecker::inlineTimeout());

        return response()->json([
            'message' => $outcome['message'],
            'queued' => false,
            'error' => !$outcome['ok'] && $request->user()->role === 'staff' ? ($outcome['detail'] ?? null) : null,
            'document' => $document->fresh()->load('validationResult'),
        ], $outcome['http']);
    }

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
