<?php

namespace App\Http\Controllers;

use App\Models\ApplicationStatusLog;
use App\Models\ContactMessage;
use App\Models\Document;
use App\Models\ProfileChangeRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| "What to do next" alerts for OAS staff
|--------------------------------------------------------------------------
| When a student does something that needs the OAS (submits, submits again after Needs Action,
| uploads a replacement, sends a message, asks for a profile correction), the staff bell shows it
| within seconds with the next step and a button that opens the right page.
| Built only from saved records (status history, documents, messages, requests): nothing new is
| stored except, per staff account, the time the alerts were last seen.
*/
class StaffAlertController extends Controller
{
    private const DAYS = 14;

    private function staffOnly(Request $request): void
    {
        if ($request->user()->role !== 'staff') {
            abort(response()->json(['message' => 'Only OAS staff have these alerts.'], 403));
        }
    }

    private function seenKey(Request $request): string
    {
        return 'staff_alerts_seen:'.$request->user()->id;
    }

    // GET /staff/alerts
    public function index(Request $request)
    {
        $this->staffOnly($request);
        $since = now()->subDays(self::DAYS);
        $alerts = collect();

        // 1. Applications the student submitted (first time, or again after Needs Action)
        ApplicationStatusLog::with(['application.student:id,first_name,last_name,student_id', 'application.scholarship:id,name,short_name'])
            ->where('to_status', 'submitted')
            ->where('created_at', '>=', $since)
            ->whereHas('changer', fn ($u) => $u->where('role', 'student'))
            ->latest()->limit(40)->get()
            ->each(function ($log) use ($alerts) {
                $app = $log->application;
                if (!$app || !in_array($app->status, ['submitted', 'under_review'], true)) {
                    return;   // already handled by staff: no longer a "next step"
                }
                $again = $log->from_status === 'needs_action';
                $program = $app->scholarship?->short_name ?: $app->scholarship?->name;
                $alerts->push([
                    'key' => "log-{$log->id}",
                    'at' => $log->created_at,
                    'who' => $this->name($app->student),
                    'title' => $again ? "Submitted the {$program} application again after fixing it" : "Submitted a {$program} application",
                    'next' => $again
                        ? 'Open the review, look at the replaced documents (AI check all), then Mark complete or send Needs action again.'
                        : 'Open the review: press AI check all, read the results, Mark as verified what you checked, then Mark complete (forward) or Needs action with a remark.',
                    'action' => 'Open review',
                    'link' => "/staff/applications?review={$app->id}",
                    'kind' => 'application',
                ]);
            });

        // 2. Replacement documents uploaded while the application waits in Needs Action
        Document::with(['application.student:id,first_name,last_name,student_id', 'application.scholarship:id,name,short_name', 'requirement:id,name'])
            ->whereNotNull('application_id')
            ->where('created_at', '>=', $since)
            ->whereHas('application', fn ($a) => $a->where('status', 'needs_action'))
            ->latest()->limit(20)->get()
            ->each(function ($doc) use ($alerts) {
                $alerts->push([
                    'key' => "doc-{$doc->id}",
                    'at' => $doc->created_at,
                    'who' => $this->name($doc->application?->student),
                    'title' => 'Uploaded a new '.$doc->typeName(),
                    'next' => 'Wait for the student to press Submit again; then the application comes back to you for review.',
                    'action' => 'View application',
                    'link' => "/staff/applications?review={$doc->application_id}",
                    'kind' => 'document',
                ]);
            });

        // 3. Messages from students not answered yet
        ContactMessage::with('student:id,first_name,last_name,student_id')
            ->where('status', 'open')->where('created_at', '>=', $since)
            ->latest()->limit(20)->get()
            ->each(fn ($m) => $alerts->push([
                'key' => "msg-{$m->id}",
                'at' => $m->created_at,
                'who' => $this->name($m->student),
                'title' => 'Sent a message: '.$m->subject,
                'next' => 'Open Student Messages, reply, then close the message.',
                'action' => 'Open conversation',
                'link' => "/staff/messages?open={$m->id}",
                'kind' => 'message',
            ]));

        // 4. Profile corrections waiting for a decision
        ProfileChangeRequest::with('student:id,first_name,last_name,student_id')
            ->where('status', 'pending')->where('created_at', '>=', $since)
            ->latest()->limit(20)->get()
            ->each(fn ($r) => $alerts->push([
                'key' => "prof-{$r->id}",
                'at' => $r->created_at,
                'who' => $this->name($r->student),
                'title' => 'Asked to correct their '.str_replace('_', ' ', $r->field),
                'next' => 'Check the proof, then Approve or Reject it on the Dashboard (Profile corrections).',
                'action' => 'Open dashboard',
                'link' => '/staff/dashboard',
                'kind' => 'profile',
            ]));

        $seen = Cache::get($this->seenKey($request));
        $sorted = $alerts->sortByDesc(fn ($a) => $a['at']->timestamp)->take(30)->values()
            ->map(fn ($a) => $a + ['new' => !$seen || $a['at']->timestamp > (int) $seen]);

        return response()->json([
            'alerts' => $sorted,
            'unseen' => $sorted->where('new', true)->count(),
        ]);
    }

    // POST /staff/alerts/seen   (the bell was opened)
    public function seen(Request $request)
    {
        $this->staffOnly($request);
        Cache::forever($this->seenKey($request), now()->timestamp);

        return response()->json(['ok' => true]);
    }

    private function name($student): string
    {
        return $student ? trim($student->first_name.' '.$student->last_name).' ('.$student->student_id.')' : 'A student';
    }
}
