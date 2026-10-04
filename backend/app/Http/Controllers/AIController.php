<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\ValidationResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Sends an uploaded document to the Python AI service (FastAPI + PaddleOCR)
 * and saves what comes back. The AI only FLAGS possible problems; OAS staff
 * always make the final decision.
 */
class AIController extends Controller
{
    public function validateDocument(Request $request, $documentId)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Only staff can run document validation.'
            ], 403);
        }

        $document = Document::with([
            'application.student',
            'requirement'
        ])->findOrFail($documentId);

        $fullPath = Storage::disk('public')->path($document->file_path);

        if (!file_exists($fullPath)) {
            return response()->json([
                'message' => 'Document file not found.'
            ], 404);
        }

        $document->update(['status' => 'processing']);

        $student = $document->application->student;

        // First + last name only: validation.py already accepts a middle
        // name printed on the document.
        $expectedName = trim($student->first_name . ' ' . $student->last_name);

        // The requirement name ("Certificate of Registration (COR)") tells
        // the AI which kind of document to expect.
        $documentLabel = $document->requirement?->name
            ?? $document->document_type
            ?? '';

        try {
            $response = Http::timeout(180)
                ->attach(
                    'file',
                    file_get_contents($fullPath),
                    $document->original_filename
                )
                ->post(
                    rtrim(config('services.ai.url'), '/') . '/validate-document',
                    [
                        'expected_name' => $expectedName,
                        'expected_student_id' => $student->student_id,
                        'document_label' => $documentLabel,
                    ]
                );
        } catch (\Throwable $e) {
            $document->update(['status' => 'needs_review']);

            return response()->json([
                'message' => 'Unable to connect to AI service. The document was sent to manual review.',
                'error' => $e->getMessage()
            ], 503);
        }

        if (!$response->successful()) {
            $document->update(['status' => 'needs_review']);

            return response()->json([
                'message' => 'AI service returned an error. The document was sent to manual review.',
                'ai_response' => $response->json()
            ], 502);
        }

        $result = $response->json();

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
                'flags' => !empty($result['flags'])
                    ? implode("\n", $result['flags'])
                    : null,
            ]
        );

        // Unreadable file -> a human must look at it.
        // Readable with any flag -> flagged. Otherwise -> validated.
        if (($result['ocr_status'] ?? 'ok') !== 'ok') {
            $status = 'needs_review';
        } elseif (
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

        return response()->json([
            'message' => match ($status) {
                'validated' => 'AI check finished: no issues found.',
                'flagged' => 'AI check finished: possible issues flagged for your review.',
                default => 'The file could not be read automatically. Please check it manually.',
            },
            'document' => $document->fresh()->load('validationResult'),
        ]);
    }
}
