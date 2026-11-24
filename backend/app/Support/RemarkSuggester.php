<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Document;

/*
|--------------------------------------------------------------------------
| Suggested remark to a student, from THEIR application's actual problems
|--------------------------------------------------------------------------
| Used by Auto-Review. It is only a suggestion: staff read and edit it, and nothing is sent until
| they press "Send back". Each student gets their own text:
|   missing document        -> which document to upload
|   wrong document          -> which file to replace with the right one
|   name not found          -> upload a copy that shows the name (or say why)
|   unclear / unreadable    -> upload a clearer copy
|   old / expired           -> upload a recent copy
|   missing information     -> what the AI could not find on it
| Documents verified by staff, and files the AI simply has not checked yet, are left out
| (they are not something the student has to fix).
*/
class RemarkSuggester
{
    public static function forApplication(Application $application, array $missing = []): ?string
    {
        $lines = [];

        foreach ($missing as $name) {
            $lines[] = "{$name}: not uploaded yet. Please upload it.";
        }

        foreach ($application->documents as $document) {
            if ($document->staff_verified_at) {
                continue;
            }
            if ($line = self::forDocument($document)) {
                $lines[] = $line;
            }
        }

        if (!$lines) {
            return null;
        }

        $first = $application->student?->first_name;

        return ($first ? "Dear {$first}, please" : 'Please')." fix the following so the OAS can continue with your application:\n- "
            .implode("\n- ", array_values(array_unique($lines)))
            ."\nThen submit your application again. If you think a note is wrong, contact the OAS.";
    }

    public static function forDocument(Document $document): ?string
    {
        $name = $document->typeName();
        $result = $document->validationResult;
        $flags = collect(preg_split('/\n/', (string) $result?->flags))->map(fn ($f) => trim($f))->filter();

        if ($document->isExpired()) {
            return "{$name}: it expired on ".$document->expiresAt()?->format('M j, Y').'. Please upload a recent copy.';
        }

        if ($document->status === 'needs_review') {
            return "{$name}: the file is unclear or could not be read (blank, blurry, dark or locked). Please upload a clearer copy: straight, well-lit, all edges visible.";
        }

        if ($document->status !== 'flagged' || !$result) {
            return null;   // passed, or not checked by the AI yet: nothing for the student to fix
        }

        if ($result->has_wrong_document) {
            return "{$name}: the uploaded file does not look like a {$name}. Please upload the correct document.";
        }

        $old = $flags->first(fn ($f) => preg_match('/more than \d+ months ago|older than|outdated|dated .* ago/i', $f));
        if ($old) {
            return "{$name}: {$old} Please upload a recent copy.";
        }

        if ($result->has_name_mismatch) {
            return "{$name}: your name could not be found on it, or it does not match your account. Please upload a copy that clearly shows your full name. If it is a parent's or guardian's document, tell the OAS.";
        }

        if ($result->has_missing_information) {
            $what = $flags->first() ?: 'some required information could not be found';

            return "{$name}: {$what} Please upload a complete copy.";
        }

        $first = $flags->first();

        return $first ? "{$name}: {$first} Please check it and upload a corrected copy if needed." : null;
    }
}
