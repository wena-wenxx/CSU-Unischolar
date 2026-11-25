<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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
