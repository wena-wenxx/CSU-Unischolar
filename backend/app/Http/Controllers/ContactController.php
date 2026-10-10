<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ContactMessage;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Contact OAS
|--------------------------------------------------------------------------
| Students: send a message and read the reply (Contact OAS page).
| Staff: list messages, reply, close (Messages page + dashboard).
*/
class ContactController extends Controller
{
    private function student(Request $request)
    {
        $student = $request->user()->role === 'student' ? $request->user()->student : null;

        if (!$student) {
            abort(response()->json(['message' => 'Only students can use Contact OAS.'], 403));
        }

        return $student;
    }

    private function staffOnly(Request $request): void
    {
        if ($request->user()->role !== 'staff') {
            abort(response()->json(['message' => 'Only OAS staff can answer messages.'], 403));
        }
    }

    // GET /student/messages: my messages, newest first. Opening the page marks replies as read.
    public function mine(Request $request)
    {
        $student = $this->student($request);

        $messages = ContactMessage::with('replier:id,name')
            ->where('student_id', $student->id)
            ->latest()
            ->latest('id')
            ->get();

        ContactMessage::where('student_id', $student->id)
            ->whereNotNull('replied_at')
            ->whereNull('reply_read_at')
            ->update(['reply_read_at' => now()]);

        return response()->json(['topics' => ContactMessage::TOPICS, 'messages' => $messages]);
    }

    // POST /student/messages   Body: topic, subject, message
    public function store(Request $request)
    {
        $student = $this->student($request);

        $data = $request->validate([
            'topic' => 'required|in:'.implode(',', array_keys(ContactMessage::TOPICS)),
            'subject' => 'required|string|max:150',
            'message' => 'required|string|max:3000',
        ]);

        // Simple flood guard: at most 5 open messages at a time.
        $open = ContactMessage::where('student_id', $student->id)->where('status', 'open')->count();
        if ($open >= 5) {
            return response()->json([
                'message' => 'You already have 5 messages waiting for an answer. Please wait for OAS to reply.'
            ], 422);
        }

        $message = ContactMessage::create($data + ['student_id' => $student->id, 'status' => 'open']);

        ActivityLog::record($request->user(), 'message.sent', "Sent a message to OAS: \"{$message->subject}\".", $message);

        return response()->json(['message' => 'Message sent. OAS will reply here.', 'item' => $message], 201);
    }

    // GET /staff/messages?status=open|answered|closed|all&q=
    public function index(Request $request)
    {
        $this->staffOnly($request);

        $filters = $request->validate([
            'status' => 'nullable|in:open,answered,closed,all',
            'q' => 'nullable|string|max:200',
        ]);

        $status = $filters['status'] ?? 'open';
        $q = trim((string) ($filters['q'] ?? ''));

        $messages = ContactMessage::with(['student:id,student_id,first_name,last_name,course,year_level,contact_number,user_id', 'student.user:id,email', 'replier:id,name'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('subject', 'like', "%{$q}%")
                ->orWhere('message', 'like', "%{$q}%")
                ->orWhereHas('student', fn ($s) => $s->where('last_name', 'like', "%{$q}%")
                    ->orWhere('first_name', 'like', "%{$q}%")
                    ->orWhere('student_id', 'like', "%{$q}%"))))
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->oldest()   // oldest open message first: answer in order
            ->limit(200)
            ->get();

        return response()->json([
            'topics' => ContactMessage::TOPICS,
            'counts' => [
                'open' => ContactMessage::where('status', 'open')->count(),
                'answered' => ContactMessage::where('status', 'answered')->count(),
                'closed' => ContactMessage::where('status', 'closed')->count(),
            ],
            'messages' => $messages,
        ]);
    }

    // POST /staff/messages/{id}/reply   Body: reply, close (optional)
    public function reply(Request $request, $id)
    {
        $this->staffOnly($request);

        $data = $request->validate([
            'reply' => 'required|string|max:3000',
            'close' => 'sometimes|boolean',
        ]);

        $message = ContactMessage::with('student:id,first_name,last_name')->findOrFail($id);

        $message->update([
            'reply' => $data['reply'],
            'replied_by' => $request->user()->id,
            'replied_at' => now(),
            'reply_read_at' => null,
            'status' => !empty($data['close']) ? 'closed' : 'answered',
        ]);

        ActivityLog::record($request->user(), 'message.replied',
            'Replied to '.trim($message->student?->first_name.' '.$message->student?->last_name).": \"{$message->subject}\".", $message);

        return response()->json(['message' => 'Reply sent. The student sees it on their Contact OAS page.', 'item' => $message->fresh()]);
    }

    // POST /staff/messages/{id}/status   Body: status open|closed
    public function setStatus(Request $request, $id)
    {
        $this->staffOnly($request);

        $status = $request->validate(['status' => 'required|in:open,closed'])['status'];
        $message = ContactMessage::findOrFail($id);
        $message->update(['status' => $status === 'open' && $message->reply ? 'answered' : $status]);

        return response()->json(['message' => $status === 'closed' ? 'Message closed.' : 'Message reopened.', 'item' => $message]);
    }
}
