<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\ValidationResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class AIController extends Controller
{
    public function validateDocument(
        Request $request,
        $documentId
    ) {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'message' => 'Only staff can run document validation.'
            ], 403);
        }

        $document = Document::with([
            'application.student',
            'requirement'
        ])->findOrFail($documentId);

        $fullPath = Storage::disk('public')
            ->path($document->file_path);

        if (!file_exists($fullPath)) {
            return response()->json([
                'message' => 'Document file not found.'
            ], 404);
        }

        $document->update([
            'status' => 'processing'
        ]);

        try {

            $student = $document
                ->application
                ->student;

            $expectedName = trim(
                $student->first_name . ' ' .
                ($student->middle_name
                    ? $student->middle_name . ' '
                    : '') .
                $student->last_name
            );

            $documentType =
                $document->document_type
                ?? $document->requirement?->name
                ?? '';

            $response = Http::timeout(120)
                ->attach(
                    'file',
                    file_get_contents($fullPath),
                    $document->original_filename
                )
                ->post(
                    rtrim(config('services.ai.url'), '/')
                        . '/validate-document',
                    [
                        'expected_name' =>
                            $expectedName,

                        'document_type' =>
                            $documentType,
                    ]
                );

            if (!$response->successful()) {

                $document->update([
                    'status' => 'needs_review'
                ]);

                return response()->json([
                    'message' =>
                        'AI service returned an error.',
                    'ai_response' =>
                        $response->json()
                ], 502);
            }

            $result = $response->json();

            $validation = ValidationResult::updateOrCreate(
                [
                    'document_id' =>
                        $document->id
                ],
                [
                    'is_complete' =>
                        $result['is_complete'] ?? false,

                    'has_name_mismatch' =>
                        $result['has_name_mismatch']
                        ?? false,

                    'has_missing_information' =>
                        $result['has_missing_information']
                        ?? false,

                    'has_wrong_document' =>
                        $result['has_wrong_document']
                        ?? false,

                    'confidence_score' =>
                        $result['confidence_score']
                        ?? null,

                    'extracted_text' =>
                        $result['extracted_text']
                        ?? null,

                    'extracted_data' =>
                        $result['extracted_data']
                        ?? null,

                    'flags' =>
                        !empty($result['flags'])
                            ? implode(
                                "\n",
                                $result['flags']
                            )
                            : null,
                ]
            );

            $hasFlags =
                !$validation->is_complete ||
                $validation->has_name_mismatch ||
                $validation->has_missing_information ||
                $validation->has_wrong_document;

            $document->update([
                'status' =>
                    $hasFlags
                        ? 'flagged'
                        : 'validated'
            ]);

            return response()->json([
                'message' =>
                    'Document validation completed.',
                'document' =>
                    $document->fresh()->load(
                        'validationResult'
                    ),
            ]);

        } catch (\Throwable $e) {

            $document->update([
                'status' => 'needs_review'
            ]);

            return response()->json([
                'message' =>
                    'Unable to connect to AI service.',
                'error' =>
                    $e->getMessage()
            ], 503);
        }
    }
}