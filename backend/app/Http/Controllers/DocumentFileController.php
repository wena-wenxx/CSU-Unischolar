<?php

namespace App\Http\Controllers;

use App\Models\Document;

class DocumentFileController extends Controller
{
    public function show($id)
    {
        $document = Document::findOrFail($id);
        $path = $document->absolutePath();

        if (!$path) {
            return response()->json(['message' => 'The file is missing.'], 404);
        }

        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $document->original_filename ?: basename($path));

        return response()->file($path, [
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
