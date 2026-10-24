<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/*
| POST /staff/ai/suggest-rules  (round 12)
| Staff paste a scholarship memo (or upload it as PDF, Word, TXT or a picture);
| the AI service suggests the eligibility rules, each with the sentence it
| came from. Uses a local language model (Ollama) when it is running, or
| simple pattern reading. NOTHING is saved here: staff tick the right values,
| they go into the form, and staff press Save.
*/
class AiRulesController extends Controller
{
    public function suggest(Request $request)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json(['message' => 'Only OAS staff can use this.'], 403);
        }

        $data = $request->validate([
            'text' => 'nullable|string|max:20000',
            'file' => 'nullable|file|max:10240|extensions:pdf,docx,txt,png,jpg,jpeg',
            'use_llm' => 'nullable|boolean',
        ], [
            'file.extensions' => 'Upload a PDF, Word (.docx), TXT, PNG or JPG file.',
        ]);
        if (!trim((string) ($data['text'] ?? '')) && !$request->hasFile('file')) {
            return response()->json(['message' => 'Paste the memo text or choose a file.'], 422);
        }

        // Only these values can be suggested (the same choices as the form).
        $options = [
            'year_levels' => Student::whereNotNull('year_level')->distinct()->orderBy('year_level')->pluck('year_level'),
            'colleges' => Student::whereNotNull('college')->distinct()->orderBy('college')->pluck('college'),
            'documents' => StudentDocumentController::STANDARD_TYPES,
        ];

        $http = Http::connectTimeout(3)->timeout(300);
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $http = $http->attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName());
        }

        try {
            $response = $http->asMultipart()->post(rtrim(config('services.ai.url'), '/').'/suggest-rules', [
                ['name' => 'text', 'contents' => (string) ($data['text'] ?? '')],
                ['name' => 'options', 'contents' => json_encode($options)],
                ['name' => 'use_llm', 'contents' => ($data['use_llm'] ?? true) ? '1' : '0'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'The AI service is not running, so the memo could not be read.'], 503);
        }

        if (!$response->successful()) {
            return response()->json(['message' => $response->json('detail') ?: 'The memo could not be read.'], 422);
        }

        ActivityLog::record($request->user(), 'scholarships.memo_read',
            'Read a scholarship memo with AI ('.$response->json('method').'); suggestions shown, nothing saved.');

        return response()->json([
            'method' => $response->json('method'),
            'suggestions' => $response->json('suggestions') ?? [],
            'warnings' => $response->json('warnings') ?? [],
            'characters' => $response->json('characters'),
        ]);
    }
}
