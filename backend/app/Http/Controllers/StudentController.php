<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AiRecommendation;
use App\Models\ApplicationStatusLog;
use App\Models\ExternalGrantee;
use App\Models\ProfileChangeRequest;
use App\Models\ScholarRecord;
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

    /*
    |--------------------------------------------------------------------------
    | STUDENT - MY PROFILE
    |--------------------------------------------------------------------------
    | Returns the logged-in user with their student profile attached.
    | The frontend reads profile.email and profile.student.*
    | Students also get their current (active) scholarship, if any.
    */

    public function profile(Request $request)
    {
        $user = $request->user()->load('student');

        if ($user->role === 'student' && !$user->student) {
            return response()->json([
                'message' => 'Student profile not found.'
            ], 404);
        }

        if ($user->role === 'student') {
            // Scholarships from outside parties (LGU, foundations) read from OAS lists.
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


    /*
    |--------------------------------------------------------------------------
    | STUDENT - UPDATE CONTACT NUMBER
    |--------------------------------------------------------------------------
    | The only field a student may change. Name, student ID, course,
    | year level and college come from the Registrar (see change requests).
    | The e-mail is the login account and is managed by the university.
    */

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


    /*
    |--------------------------------------------------------------------------
    | STUDENT - GWA AND FAMILY INCOME (scholarship matching)
    |--------------------------------------------------------------------------
    | PATCH /profile/eligibility   Body: gwa (1.00-5.00), family_income (pesos)
    | Used by AI Recommendations. Changing them removes the OAS verification
    | until OAS checks them again against the Grades and the Parents' ITR.
    */

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


    /*
    |--------------------------------------------------------------------------
    | STUDENT - MY SCHOLARSHIP HISTORY
    |--------------------------------------------------------------------------
    | Every scholarship this student has been tagged as a grantee for
    | (active, completed or inactive), newest first, with payroll entries.
    */

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


    /*
    |--------------------------------------------------------------------------
    | STUDENT - NOTIFICATIONS
    |--------------------------------------------------------------------------
    | Status changes on the student's own applications, newest first.
    | read_at is set when the student opens the list.
    */

    public function notifications(Request $request)
    {
        $student = $this->student($request);

        $logs = ApplicationStatusLog::with('application.scholarship:id,name')
            ->whereHas('application', fn ($q) => $q->where('student_id', $student->id))
            // A draft the student made is not news; a draft OAS started for them is.
            ->where(fn ($q) => $q->where('to_status', '!=', 'draft')->orWhere('changed_by', '!=', $request->user()->id))
            ->latest()
            ->latest('id')
            ->limit(20)
            ->get();

        // Replies from OAS to the student's Contact OAS messages, not yet seen.
        $replies = \App\Models\ContactMessage::where('student_id', $student->id)
            ->whereNotNull('replied_at')
            ->whereNull('reply_read_at')
            ->count();

        // "You may be eligible" invitations from OAS (AI Recommendations), last 60 days.
        $invitations = AiRecommendation::with('scholarship:id,name')
            ->where('student_id', $student->id)
            ->where('staff_status', 'invited')
            ->where('invited_at', '>=', now()->subDays(60))
            ->latest('invited_at')
            ->get(['id', 'scholarship_id', 'invited_at'])
            ->map(fn ($r) => ['scholarship_id' => $r->scholarship_id, 'scholarship' => $r->scholarship?->name, 'invited_at' => $r->invited_at]);

        return response()->json([
            'unread' => $logs->whereNull('read_at')->count(),
            'items' => $logs->values(),
            'unread_replies' => $replies,
            'invitations' => $invitations->values(),
        ]);
    }

    public function markNotificationsRead(Request $request)
    {
        $student = $this->student($request);

        ApplicationStatusLog::whereHas('application', fn ($q) => $q->where('student_id', $student->id))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Notifications marked as read.']);
    }


    /*
    |--------------------------------------------------------------------------
    | STUDENT - REQUEST A CORRECTION OF REGISTRAR INFORMATION
    |--------------------------------------------------------------------------
    */

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

    /*
    | GET /student/matches  (round 12)
    | "Scholarships that fit you": programs whose rules the student meets
    | (or that only need their GWA / income), with what is left to do.
    | Only programs students can see now; failed rules, programs already
    | applied for and matches OAS marked wrong are left out. No percentage
    | is shown to students: it could be read as a chance of approval.
    */
    public function matches(Request $request)
    {
        $student = $this->student($request);
        EligibilityEngine::recalculate([$student->id]);   // fresh after a new upload or profile change

        $fits = ProgramFit::where('student_id', $student->id)->get()->keyBy('scholarship_id');

        $rows = AiRecommendation::with('scholarship')
            ->where('student_id', $student->id)
            ->whereIn('verdict', ['eligible', 'no_data'])
            ->whereNull('applied_status')
            ->where('staff_status', '!=', 'wrong')
            ->whereHas('scholarship', fn ($q) => $q->visibleToStudents())
            ->get()
            ->map(function (AiRecommendation $r) use ($fits) {
                $todo = NextSteps::forStudent($r);
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
                    'fit' => $fits[$program->id]?->summary(),
                ];
            })
            ->sortBy([
                fn ($a, $b) => ['ready' => 0, 'almost' => 1, 'need_info' => 2][$a['status']] <=> ['ready' => 0, 'almost' => 1, 'need_info' => 2][$b['status']],
                // AI field fit: programs for the student's field first (then open-to-all, then others).
                fn ($a, $b) => (['high' => 0, 'medium' => 1, 'any' => 2][$a['fit']['level'] ?? ''] ?? 3) <=> (['high' => 0, 'medium' => 1, 'any' => 2][$b['fit']['level'] ?? ''] ?? 3),
                fn ($a, $b) => $a['to_do_count'] <=> $b['to_do_count'],
                fn ($a, $b) => strcmp((string) $a['deadline'] ?: '9999', (string) $b['deadline'] ?: '9999'),
            ])
            ->values();

        // Programs the student applies for at the agency (no rules here), that match their field.
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

    /*
    | PATCH /profile/interests  (round 12)  Body: interests (optional, max 300)
    | "My field and goals": helps the AI find scholarships for the student's field.
    | It never affects eligibility.
    */
    public function updateInterests(Request $request)
    {
        $student = $this->student($request);
        $data = $request->validate(['interests' => 'nullable|string|max:300']);
        $student->update(['interests' => trim((string) ($data['interests'] ?? '')) ?: null]);
        FitScorer::refresh([$student->id]);

        return response()->json(['message' => 'Saved. Your scholarship matches are updated.', 'student' => $student->fresh()]);
    }
}
