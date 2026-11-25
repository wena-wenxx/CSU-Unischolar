<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\ActivityLog;
use App\Models\AiRecommendation;
use App\Models\ApplicationStatusLog;
use App\Models\ExternalGrantee;
use App\Models\ProfileChangeRequest;
use App\Models\ScholarRecord;
use App\Models\ScholarshipNotification;
use App\Support\EligibilityEngine;
use App\Support\NextSteps;
use App\Support\FitScorer;
use App\Models\ProgramFit;
use App\Models\Scholarship;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    private function student(Request $request)
    {
        if ($request->user()->role !== 'student') {
            abort(response()->json(['message' => 'Only students can do this.'], 403));
        }

        $student = $request->user()->student;

        if (!$student) {
            abort(response()->json(['message' => 'Student profile not found.'], 404));
        }

        return $student;
    }

    public function profile(Request $request)
    {
        $user = $request->user()->load('student');

        if ($user->role === 'student' && !$user->student) {
            return response()->json([
                'message' => 'Student profile not found.'
            ], 404);
        }

        if ($user->role === 'student') {
            $user->setAttribute(
                'external_scholarships',
                ExternalGrantee::activeFor($user->student->id)
                    ->latest()
                    ->get(['id', 'program_name', 'agency', 'school_year', 'created_at'])
            );
            $user->setAttribute('eligibility_verified', EligibilityEngine::dataVerified($user->student));

            $user->setAttribute(
                'active_scholar_record',
                ScholarRecord::with(['scholarship:id,name,provider,amount', 'payrollRecords'])
                    ->where('student_id', $user->student->id)
                    ->where('status', 'active')
                    ->latest('grantee_tagged_at')
                    ->first()
            );
        }

        return response()->json($user);
    }

    public function updateProfile(Request $request)
    {
        $student = $this->student($request);

        $data = $request->validate([
            'contact_number' => ['required', 'regex:/^09\d{9}$/'],
        ], [
            'contact_number.regex' => 'Enter an 11-digit mobile number that starts with 09, e.g. 09171234567.',
        ]);

        $student->update($data);
        ActivityLog::record($request->user(), 'profile.contact_updated', 'Updated their contact number.', $student);

        return response()->json([
            'message' => 'Contact number updated.',
            'student' => $student->fresh(),
        ]);
    }

    public function updateEligibility(Request $request)
    {
        $student = $this->student($request);

        $data = $request->validate([
            'gwa' => 'required|numeric|min:1|max:5',
            'family_income' => 'required|numeric|min:0|max:100000000',
        ], [
            'gwa.min' => 'GWA is between 1.00 (highest) and 5.00.',
            'gwa.max' => 'GWA is between 1.00 (highest) and 5.00.',
        ]);

        $gwa = round((float) $data['gwa'], 2);
        $income = round((float) $data['family_income'], 2);
        $changed = $student->gwa === null || (float) $student->gwa !== $gwa
            || $student->family_income === null || (float) $student->family_income !== $income;

        if ($changed) {
            $student->update([
                'gwa' => $gwa,
                'family_income' => $income,
                'eligibility_updated_at' => now(),
                'eligibility_verified_at' => null,
                'eligibility_verified_by' => null,
            ]);
            EligibilityEngine::recalculate([$student->id]);
            ActivityLog::record($request->user(), 'profile.eligibility_updated', 'Updated their GWA and family income.', $student);
        }

        return response()->json([
            'message' => $changed ? 'Saved. The OAS will check them against your Grades and your parents\' ITR.' : 'Nothing changed.',
            'student' => $student->fresh(),
        ]);
    }

    public function history(Request $request)
    {
        $student = $this->student($request);

        $records = ScholarRecord::with([
            'scholarship',
            'payrollRecords'
        ])
            ->where('student_id', $student->id)
            ->latest()
            ->get();

        return response()->json($records);
    }

    public function notifications(Request $request)
    {
        $student = $this->student($request);

        $logs = ApplicationStatusLog::with('application.scholarship:id,name')
            ->whereHas('application', fn ($q) => $q->where('student_id', $student->id))
            ->where(fn ($q) => $q->where('to_status', '!=', 'draft')->orWhere('changed_by', '!=', $request->user()->id))
            ->latest()
            ->latest('id')
            ->limit(20)
            ->get();

        $replies = \App\Models\ContactMessage::where('student_id', $student->id)
            ->whereNotNull('replied_at')
            ->whereNull('reply_read_at')
            ->count();

        $invitations = AiRecommendation::with('scholarship:id,name')
            ->where('student_id', $student->id)
            ->where('staff_status', 'invited')
            ->whereNotIn('id', ScholarshipNotification::where('student_id', $student->id)->whereNotNull('ai_recommendation_id')->select('ai_recommendation_id'))
            ->where('invited_at', '>=', now()->subDays(60))
            ->latest('invited_at')
            ->get(['id', 'scholarship_id', 'invited_at'])
            ->map(fn ($r) => ['scholarship_id' => $r->scholarship_id, 'scholarship' => $r->scholarship?->name, 'invited_at' => $r->invited_at]);

        $opportunities = ScholarshipNotification::with('scholarship:id,name,short_name')
            ->where('student_id', $student->id)
            ->latest('sent_at')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn ($notification) => [
                'id' => $notification->id,
                'scholarship_id' => $notification->scholarship_id,
                'scholarship' => $notification->scholarship?->name,
                'message' => $notification->message,
                'sent_at' => $notification->sent_at,
                'read_at' => $notification->read_at,
                'availability' => $notification->scholarship?->availability,
                'agency_direct' => $notification->scholarship?->isAgencyDirect(),
            ]);

        return response()->json([
            'unread' => $logs->whereNull('read_at')->count() + $opportunities->whereNull('read_at')->count(),
            'items' => $logs->values(),
            'unread_replies' => $replies,
            'invitations' => $invitations->values(),
            'opportunities' => $opportunities->values(),
        ]);
    }

    public function markNotificationsRead(Request $request)
    {
        $student = $this->student($request);

        ApplicationStatusLog::whereHas('application', fn ($q) => $q->where('student_id', $student->id))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        ScholarshipNotification::where('student_id', $student->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Notifications marked as read.']);
    }

    public function changeRequests(Request $request)
    {
        $student = $this->student($request);

        return response()->json(
            ProfileChangeRequest::where('student_id', $student->id)->latest()->get()
        );
    }

    public function storeChangeRequest(Request $request)
    {
        $student = $this->student($request);

        $data = $request->validate([
            'field' => 'required|in:'.implode(',', array_keys(ProfileChangeRequest::FIELDS)),
            'requested_value' => 'required|string|max:255',
            'reason' => 'nullable|string|max:1000',
        ]);

        $pendingSame = ProfileChangeRequest::where('student_id', $student->id)
            ->where('field', $data['field'])
            ->where('status', 'pending')
            ->exists();

        if ($pendingSame) {
            return response()->json([
                'message' => 'You already have a pending request for this field. Please wait for OAS to check it.'
            ], 422);
        }

        $changeRequest = ProfileChangeRequest::create($data + [
            'student_id' => $student->id,
            'status' => 'pending',
        ]);

        ActivityLog::record($request->user(), 'profile_request.sent', 'Asked OAS to correct their '.ProfileChangeRequest::FIELDS[$data['field']].'.', $changeRequest);

        return response()->json([
            'message' => 'Request sent to OAS.',
            'request' => $changeRequest,
        ], 201);
    }

    public function matches(Request $request)
    {
        $student = $this->student($request);
        $fitIsFresh = ProgramFit::where('student_id', $student->id)->where('computed_at', '>=', now()->subMinutes(10))->exists()
            || !\Illuminate\Support\Facades\Cache::add("fit_tried:{$student->id}", 1, 600);
        EligibilityEngine::recalculate([$student->id], !$fitIsFresh);

        $fits = ProgramFit::where('student_id', $student->id)->get()->keyBy('scholarship_id');

        $sentToOas = Document::with('application:id,status')
            ->where('student_id', $student->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Document $d) => $d->typeName())
            ->filter(fn (Document $d) => $d->application_id
                && !in_array($d->application?->status, ['draft', 'needs_action'], true))
            ->keys()
            ->all();

        $rows = AiRecommendation::with('scholarship')
            ->where('student_id', $student->id)
            ->whereIn('verdict', ['eligible', 'no_data'])
            ->whereNull('applied_status')
            ->where('staff_status', '!=', 'wrong')
            ->whereHas('scholarship', fn ($q) => $q->visibleToStudents())
            ->get()
            ->map(function (AiRecommendation $r) use ($fits, $sentToOas) {
                $todo = NextSteps::forStudent($r, $sentToOas);
                $toDo = count(array_filter($todo, fn ($t) => $t['kind'] !== 'wait'));
                $program = $r->scholarship;

                return [
                    'scholarship_id' => $program->id,
                    'name' => $program->name,
                    'short_name' => $program->short_name,
                    'provider' => $program->provider,
                    'deadline' => $program->application_end,
                    'availability' => $program->availability,
                    'closed_reason' => $program->closedReason(),
                    'agency_direct' => $program->isAgencyDirect(),
                    'status' => $r->verdict === 'no_data' ? 'need_info' : ($todo ? 'almost' : 'ready'),
                    'todo' => $todo,
                    'to_do_count' => $toDo,
                    'fit' => $fits->get($program->id)?->summary(),
                ];
            })
            ->sortBy([
                fn ($a, $b) => ['ready' => 0, 'almost' => 1, 'need_info' => 2][$a['status']] <=> ['ready' => 0, 'almost' => 1, 'need_info' => 2][$b['status']],
                fn ($a, $b) => (['high' => 0, 'medium' => 1, 'any' => 2][$a['fit']['level'] ?? ''] ?? 3) <=> (['high' => 0, 'medium' => 1, 'any' => 2][$b['fit']['level'] ?? ''] ?? 3),
                fn ($a, $b) => $a['to_do_count'] <=> $b['to_do_count'],
                fn ($a, $b) => strcmp((string) $a['deadline'] ?: '9999', (string) $b['deadline'] ?: '9999'),
            ])
            ->values();

        $listed = $rows->pluck('scholarship_id')->all();
        $applied = $student->applications()->pluck('scholarship_id')->all();
        $alsoFits = $fits->filter(fn ($f) => $f->level === 'high' && !in_array($f->scholarship_id, $listed, true) && !in_array($f->scholarship_id, $applied, true))
            ->map(fn ($f) => $f->scholarship_id)->values();
        $agency = Scholarship::visibleToStudents()->whereIn('id', $alsoFits)->get()
            ->filter(fn ($p) => $p->isAgencyDirect())
            ->take(3)
            ->map(fn ($p) => ['scholarship_id' => $p->id, 'name' => $p->name, 'provider' => $p->provider, 'fields' => $fits[$p->id]->fields ?? []])
            ->values();

        return response()->json([
            'matches' => $rows,
            'also_fits' => $agency,
            'data_checked' => EligibilityEngine::dataVerified($student),
            'has_interests' => (bool) $student->interests,
        ]);
    }

    public function updateInterests(Request $request)
    {
        $student = $this->student($request);
        $data = $request->validate(['interests' => 'nullable|string|max:300']);
        $student->update(['interests' => trim((string) ($data['interests'] ?? '')) ?: null]);
        FitScorer::refresh([$student->id]);

        return response()->json(['message' => 'Saved. Your scholarship matches are updated.', 'student' => $student->fresh()]);
    }
}
