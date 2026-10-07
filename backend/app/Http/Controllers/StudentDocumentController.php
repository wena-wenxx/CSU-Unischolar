<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Document;
use App\Models\ScholarshipRequirement;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| My Documents (students)
|--------------------------------------------------------------------------
| Every file a student ever uploaded, for any application or saved here
| directly, so it can be checked, replaced and reused when applying again.
|
| A document belongs to a student (documents.student_id). It may also belong
| to one application (documents.application_id). Reusing a file for another
| application makes another row that points to the same stored file.
*/
class StudentDocumentController extends Controller
{
    // The nine document types the OAS asks for.
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

    // GET /student/documents
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

    // POST /student/documents   (file + document_type): save a file before applying
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

    // POST /student/documents/{id}/replace   (file): a newer copy of the same type
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

        // Older copies stay in the list as history; submitted applications
        // keep the file OAS is already reviewing.
        return response()->json([
            'message' => $this->savedMessage($type, $updated),
            'document' => $this->present($document->load(['requirement', 'validationResult', 'application'])),
            'updated_applications' => $updated,
        ], 201);
    }

    private function saveFile(Request $request, int $studentId, string $type): Document
    {
        $file = $request->file('file');

        return Document::create([
            'student_id' => $studentId,
            'application_id' => null,
            'scholarship_requirement_id' => null,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $file->store('documents', 'public'),
            'document_type' => $type,
            'status' => 'uploaded',
        ]);
    }

    /*
    | The new file also replaces this type in the student's applications that
    | can still change (draft or needs action). Submitted ones are left alone.
    | Returns the names of the scholarships that were updated.
    */
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
