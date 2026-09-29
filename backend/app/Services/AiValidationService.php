<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Student;
use App\Models\ValidationResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Sends an uploaded document to the Python AI service (FastAPI + PaddleOCR)
 * and saves what comes back. The AI only FLAGS things - a human (OAS staff)
 * always makes the final decision.
 */
class AiValidationService
{
    public function validate(Document $document, Student $student, ?string $label = null): Document
    {
        $baseUrl = rtrim(env('AI_SERVICE_URL', 'http://127.0.0.1:8001'), '/');
        $fullPath = Storage::disk('public')->path($document->file_path);
        $expectedName = trim($student->first_name.' '.$student->last_name);

        try {
            $response = Http::timeout(180)
                ->attach('file', file_get_contents($fullPath), $document->original_filename)
                ->post($baseUrl.'/validate-document', [
                    'expected_name' => $expectedName,
                    'expected_student_id' => $student->student_id,
                    'document_label' => $label ?? '',
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException('AI service answered with status '.$response->status());
            }

            $data = $response->json();
        } catch (\Throwable $e) {
            // AI service is off or crashed: do NOT break the upload. Send to human review.
            $document->update(['status' => 'needs_review']);

            ValidationResult::updateOrCreate(
                ['document_id' => $document->id],
                [
                    'is_complete' => false,
                    'has_name_mismatch' => false,
                    'has_missing_information' => false,
                    'has_wrong_document' => false,
                    'confidence_score' => null,
                    'extracted_text' => null,
                    'extracted_data' => null,
                    'flags' => 'AI service unavailable - needs manual review. ('.$e->getMessage().')',
                ]
            );

            return $document->load('validationResult');
        }

        $ocrOk = ($data['ocr_status'] ?? 'failed') === 'ok';
        $hasFlag = ! empty($data['has_name_mismatch'])
            || ! empty($data['has_missing_information'])
            || ! empty($data['has_wrong_document'])
            || empty($data['is_complete']);

        $status = ! $ocrOk ? 'needs_review' : ($hasFlag ? 'flagged' : 'validated');

        ValidationResult::updateOrCreate(
            ['document_id' => $document->id],
            [
                'is_complete' => (bool) ($data['is_complete'] ?? false),
                'has_name_mismatch' => (bool) ($data['has_name_mismatch'] ?? false),
                'has_missing_information' => (bool) ($data['has_missing_information'] ?? false),
                'has_wrong_document' => (bool) ($data['has_wrong_document'] ?? false),
                'confidence_score' => $data['confidence_score'] ?? null,
                'extracted_text' => $data['extracted_text'] ?? null,
                'extracted_data' => $data['extracted_data'] ?? null,
                'flags' => implode('; ', $data['flags'] ?? []),
            ]
        );

        $document->update(['status' => $status]);

        return $document->load('validationResult');
    }
}
