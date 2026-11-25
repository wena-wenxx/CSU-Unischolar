<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Document;
use App\Models\ScholarshipRequirement;
use Illuminate\Http\Request;

class StudentDocumentController extends Controller
{
    public const STANDARD_TYPES = [
        'Certificate of Registration (COR)',
        'Certificate of Grades',
        'Valid ID',
        'Certificate of Indigency',
        'Birth Certificate',
        'Barangay Clearance',
        'Certificate of Good Moral Character',
        "Parents' Income Tax Return",
        'Recommendation Letter',
    ];

    private function student(Request $request)
    {
        $student = $request->user()->role === 'student' ? $request->user()->student : null;

        if (!$student) {
            abort(response()->json(['message' => 'Only students have My Documents.'], 403));
        }

        return $student;
    }

    private function present(Document $document): array
    {
        $expires = $document->expiresAt();

        return [
            'id' => $document->id,
            'type' => $document->typeName(),
            'original_filename' => $document->original_filename,
            'file_path' => $document->file_path,
            'file_url' => $document->file_url,
            'status' => $document->status,
            'uploaded_at' => $document->created_at,
            'expires_at' => $expires?->toDateString(),
            'is_expired' => $document->isExpired(),
            'flags' => $document->validationResult?->flags,
            'checked_at' => $document->validationResult?->updated_at,
            'application' => $document->application ? [
                'id' => $document->application->id,
                'status' => $document->application->status,
                'scholarship' => $document->application->scholarship?->name,
            ] : null,
        ];
    }

    public function index(Request $request)
    {
        $student = $this->student($request);

        $documents = Document::with(['requirement:id,name', 'validationResult', 'application.scholarship:id,name'])
            ->where('student_id', $student->id)
            ->latest()
            ->latest('id')
            ->get()
            ->map(fn ($d) => $this->present($d));

        return response()->json([
            'types' => self::STANDARD_TYPES,
            'validity_months' => Document::VALIDITY_MONTHS,
            'documents' => $documents->values(),
        ]);
    }

    public function store(Request $request)
    {
        $student = $this->student($request);

        $data = $request->validate([
            'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png',
            'document_type' => 'required|string|max:255',
        ]);

        $document = $this->saveFile($request, $student->id, $data['document_type']);
        $updated = $this->updateEditableApplications($student->id, $document);

        return response()->json([
            'message' => $this->savedMessage($data['document_type'], $updated),
            'document' => $this->present($document->load(['requirement', 'validationResult', 'application'])),
            'updated_applications' => $updated,
        ], 201);
    }

    public function replace(Request $request, $id)
    {
        $student = $this->student($request);

        $request->validate([
            'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png',
        ]);

        $old = Document::with('requirement')->findOrFail($id);

        if ($old->student_id !== $student->id) {
            return response()->json(['message' => 'You can only replace your own documents.'], 403);
        }

        $type = $old->typeName();
        $document = $this->saveFile($request, $student->id, $type);
        $updated = $this->updateEditableApplications($student->id, $document);

        return response()->json([
            'message' => $this->savedMessage($type, $updated),
            'document' => $this->present($document->load(['requirement', 'validationResult', 'application'])),
            'updated_applications' => $updated,
        ], 201);
    }

    public function destroy(Request $request, $id)
    {
        $student = $this->student($request);
        $document = Document::with('application:id,status')->where('student_id', $student->id)->findOrFail($id);

        if ($document->application_id && !in_array($document->application?->status, ['draft', 'needs_action'], true)) {
            return response()->json(['message' => 'This file is part of an application you already submitted, so the OAS keeps it. Contact the OAS if it is wrong.'], 422);
        }
        if ($document->status === 'processing') {
            \App\Support\DocumentChecker::cancel($document);
        }

        $name = $document->original_filename;
        $path = $document->file_path;
        $document->delete();
        if ($path) {
            Document::deleteFileIfUnused($path);
        }
        \App\Models\ActivityLog::record($request->user(), 'document.deleted', "Deleted the file {$name}.");

        return response()->json(['message' => "{$name} was deleted."]);
    }

    private function saveFile(Request $request, int $studentId, string $type): Document
    {
        $file = $request->file('file');

        return Document::create([
            'student_id' => $studentId,
            'application_id' => null,
            'scholarship_requirement_id' => null,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $file->store('documents', Document::DISK),
            'document_type' => $type,
            'status' => 'uploaded',
        ]);
    }

    private function updateEditableApplications(int $studentId, Document $source): array
    {
        $updated = [];

        $applications = Application::with('scholarship:id,name')
            ->where('student_id', $studentId)
            ->whereIn('status', ['draft', 'needs_action'])
            ->get();

        foreach ($applications as $application) {
            $requirement = ScholarshipRequirement::where('scholarship_id', $application->scholarship_id)
                ->where('name', $source->document_type)
                ->first();

            if (!$requirement) {
                continue;
            }

            foreach (Document::where('application_id', $application->id)->where('scholarship_requirement_id', $requirement->id)->get() as $old) {
                $old->delete();
                Document::deleteFileIfUnused($old->file_path);
            }

            Document::create([
                'student_id' => $studentId,
                'application_id' => $application->id,
                'scholarship_requirement_id' => $requirement->id,
                'original_filename' => $source->original_filename,
                'file_path' => $source->file_path,
                'document_type' => $requirement->name,
                'status' => 'uploaded',
            ]);

            $updated[] = $application->scholarship?->name;
        }

        return $updated;
    }

    private function savedMessage(string $type, array $updated): string
    {
        $message = "{$type} saved.";

        if ($updated) {
            $message .= ' Also updated in your '.(count($updated) === 1 ? 'application' : 'applications').': '.implode(', ', $updated).'.';
        }

        return $message;
    }
}
