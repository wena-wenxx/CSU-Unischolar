<?php

namespace App\Http\Controllers;

use App\Models\Document;

/*
| GET /api/files/documents/{id}?expires=...&signature=...
| Opens a student document. The link is signed and expires after 30
| minutes; the API only gives it to the student who owns the document and
| to OAS staff (it is in the document data they are allowed to see). A link
| that was changed, copied after it expired, or guessed does not work.
*/
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
