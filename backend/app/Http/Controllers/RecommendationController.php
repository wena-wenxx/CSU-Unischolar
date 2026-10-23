<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AiRecommendation;
use App\Models\Application;
use App\Models\ApplicationStatusLog;
use App\Models\RecommendationLog;
use App\Models\Scholarship;
use App\Models\Student;
use App\Support\ApplicationNotifier;
use App\Support\EligibilityEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| AI Recommendations (staff)
|--------------------------------------------------------------------------
| Shows the eligibility scores calculated by App\Support\EligibilityEngine
| and records what staff decide. The AI never approves anyone:
|   Send invitation     e-mail: "you may be eligible" (the student decides)
|   Add to scholarship  starts a DRAFT application for the student, after
|                       staff confirm; the normal review and the agency's
|                       decision still apply
|   Dismiss             not suitable / not now
|   Mark as wrong       the AI got it wrong (a reason is required), so the
|                       rule or the data can be checked
|   Restore             back to "new"
| Every page of recommendations shown, and every decision, is written to
| recommendation_logs (see RecommendationLog).
*/
class RecommendationController extends Controller
{
    private const VIEWS = ['recommended', 'no_data', 'not_eligible', 'acted', 'dismissed', 'wrong', 'all'];

    private function staffOnly(Request $request): void
    {
        if ($request->user()->role !== 'staff') {
            abort(response()->json(['message' => 'Only OAS staff can use AI Recommendations.'], 403));
        }
    }

    /** The filters shared by the list and the tab counts. */
    private function filtered(array $f)
    {
        $words = array_filter(preg_split('/\s+/', trim((string) ($f['q'] ?? ''))));

        return AiRecommendation::query()
            ->when($f['view'] ?? 'recommended', function ($q, $view) {
                match ($view) {
                    'recommended' => $q->where('verdict', 'eligible')->whereNotIn('staff_status', ['dismissed', 'wrong']),
                    'no_data' => $q->where('verdict', 'no_data')->whereNotIn('staff_status', ['dismissed', 'wrong']),
                    'not_eligible' => $q->where('verdict', 'not_eligible')->whereNotIn('staff_status', ['dismissed', 'wrong']),
                    'acted' => $q->whereIn('staff_status', ['invited', 'added']),
                    'dismissed' => $q->where('staff_status', 'dismissed'),
                    'wrong' => $q->where('staff_status', 'wrong'),
                    default => null,
                };
            })
            ->when(($f['applied'] ?? '') === 'no', fn ($q) => $q->where(fn ($w) => $w->whereNull('applied_status')))
            ->when(($f['applied'] ?? '') === 'yes', fn ($q) => $q->whereNotNull('applied_status'))
            ->when(!empty($f['checked']), fn ($q) => $q->where('data_verified', true))
            ->when(isset($f['min']), fn ($q) => $q->where('score', '>=', (int) $f['min']))
            ->when(isset($f['max']), fn ($q) => $q->where('score', '<=', (int) $f['max']))
            ->when($words, fn ($q) => $q->whereHas('student', function ($s) use ($words) {
                foreach ($words as $word) {
                    $like = '%'.$word.'%';
                    $s->where(fn ($w) => $w->where('student_id', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like));
                }
            }));
    }

    /*
    | GET /staff/recommendations
    | view          recommended (default) | no_data | not_eligible | acted | dismissed | wrong | all
    | scholarship_id, applied (no | yes), min, max (0-100), q (name or Student ID)
    | checked=1     only students whose GWA and income OAS has checked
    | sort          score_desc (default) | score_asc | name
    | page          50 per page ("Show more")
    | export=1      every match at once (CSV export, up to 5000)
    */
    public function index(Request $request)
    {
        $this->staffOnly($request);

        $f = $request->validate([
            'view' => 'nullable|in:'.implode(',', self::VIEWS),
            'scholarship_id' => 'nullable|integer',
            'applied' => 'nullable|in:no,yes',
            'checked' => 'nullable|boolean',
            'min' => 'nullable|integer|min:0|max:100',
            'max' => 'nullable|integer|min:0|max:100',
            'q' => 'nullable|string|max:100',
            'sort' => 'nullable|in:score_desc,score_asc,name',
            'page' => 'nullable|integer|min:1',
            'export' => 'nullable|boolean',
        ]);
        $f['view'] = $f['view'] ?? 'recommended';
        $export = (bool) ($f['export'] ?? false);
        $perPage = $export ? 5000 : 50;
        $page = $export ? 1 : (int) ($f['page'] ?? 1);

        // Tab counts: same filters, every program.
        $counts = $this->filtered($f)
            ->select('scholarship_id', DB::raw('count(*) as total'))
            ->groupBy('scholarship_id')
            ->pluck('total', 'scholarship_id');

        $query = $this->filtered($f)
            ->when($f['scholarship_id'] ?? null, fn ($q, $id) => $q->where('scholarship_id', $id));
        $total = (clone $query)->count();

        $sort = $f['sort'] ?? 'score_desc';
        $query->join('students', 'students.id', '=', 'ai_recommendations.student_id')
            ->select('ai_recommendations.*');
        if ($sort === 'name') {
            $query->orderBy('students.last_name')->orderBy('students.first_name');
        } else {
            $query->orderBy('ai_recommendations.score', $sort === 'score_asc' ? 'asc' : 'desc')
                ->orderBy('students.last_name')->orderBy('students.first_name');
        }

        $rows = $query->with([
            'student:id,student_id,first_name,middle_name,last_name,course,year_level,college',
            'scholarship:id,name,short_name,application_mode,status,application_start,application_end',
        ])
            ->skip(($page - 1) * $perPage)->take($perPage)
            ->get();

        // Audit: these recommendations were shown to this staff member.
        if ($export) {
            ActivityLog::record($request->user(), 'recommendations.exported', "Exported {$rows->count()} AI recommendations (CSV).");
        } else {
            RecommendationLog::recordShown($rows, $request->user());
        }

        $scholarships = EligibilityEngine::scoredScholarships();
        $unscored = Scholarship::where('status', 'active')
            ->whereNotIn('id', $scholarships->pluck('id'))
            ->orderBy('name')
            ->pluck('name');

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->present($r))->values(),
            'total' => $total,
            'page' => $page,
            'has_more' => !$export && $page * $perPage < $total,
            'tabs' => $scholarships->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'short_name' => $s->short_name, 'count' => (int) ($counts[$s->id] ?? 0),
            ])->values(),
            'all_count' => (int) $counts->sum(),
            // max() returns a plain string; send it as ISO time so the browser shows the right hour.
            'computed_at' => ($last = AiRecommendation::max('computed_at')) ? \Illuminate\Support\Carbon::parse($last)->toJSON() : null,
            'unscored' => $unscored,
        ]);
    }

    private function present(AiRecommendation $r): array
    {
        return [
            'id' => $r->id,
            'student' => $r->student,
            'scholarship' => $r->scholarship ? [
                'id' => $r->scholarship->id,
                'name' => $r->scholarship->name,
                'short_name' => $r->scholarship->short_name,
                'agency_direct' => $r->scholarship->isAgencyDirect(),
                'is_open' => $r->scholarship->is_open,
            ] : null,
            'score' => $r->score,
            'met_count' => $r->met_count,
            'total_count' => $r->total_count,
            'verdict' => $r->verdict,
            'checks' => $r->checks ?? [],
            'calculation' => $r->calculation,
            'applied_status' => $r->applied_status,
            'data_verified' => $r->data_verified,
            'staff_status' => $r->staff_status,
            'staff_note' => $r->staff_note,
            'invited_at' => $r->invited_at,
            'decided_at' => $r->decided_at,
            'computed_at' => $r->computed_at,
        ];
    }

    /*
    | GET /staff/recommendations/{id}
    | The full reasoning: every check, the student's data, the scholarship's
    | rules, and the history of this recommendation (shown / decisions).
    */
    public function show(Request $request, $id)
    {
        $this->staffOnly($request);

        $r = AiRecommendation::with(['student', 'scholarship', 'decider:id,name'])->findOrFail($id);
        $student = $r->student;

        $history = RecommendationLog::with('user:id,name')
            ->where('ai_recommendation_id', $r->id)
            ->where('action', '!=', 'shown')
            ->latest('created_at')->latest('id')
            ->limit(20)
            ->get();

        return response()->json($this->present($r) + [
            'decided_by' => $r->decider?->name,
            'student_data' => [
                'gwa' => $student->gwa,
                'family_income' => $student->family_income,
                'year_level' => $student->year_level,
                'college' => $student->college,
                'course' => $student->course,
                'updated_at' => $student->eligibility_updated_at,
                'verified_at' => $student->eligibility_verified_at,
                'verified' => EligibilityEngine::dataVerified($student),
            ],
            'times_shown' => RecommendationLog::where('ai_recommendation_id', $r->id)->where('action', 'shown')->count(),
            'history' => $history->map(fn ($log) => [
                'action' => $log->action,
                'label' => RecommendationLog::ACTIONS[$log->action] ?? $log->action,
                'by' => $log->user?->name,
                'score' => $log->score,
                'note' => $log->note,
                'at' => $log->created_at,
            ]),
            'application_id' => Application::where('student_id', $r->student_id)
                ->where('scholarship_id', $r->scholarship_id)->value('id'),
        ]);
    }

    // POST /staff/recommendations/recalculate
    public function recalculate(Request $request)
    {
        $this->staffOnly($request);

        $result = EligibilityEngine::recalculate();

        ActivityLog::record($request->user(), 'recommendations.recalculated',
            "Recalculated AI Recommendations: {$result['rows']} scores, {$result['eligible']} eligible.");

        return response()->json([
            'message' => "Recalculated {$result['rows']} recommendations ({$result['students']} students x {$result['scholarships']} programs). {$result['eligible']} are eligible.",
            'result' => $result,
        ]);
    }

    /** A reason is required when staff go against the AI (e.g. invite a "not eligible" student). */
    private function needsReason(AiRecommendation $r, ?string $note, string $action): void
    {
        if ($r->verdict !== 'eligible' && trim((string) $note) === '') {
            $what = $r->verdict === 'no_data' ? 'some data is missing' : 'a must-have rule is not met';
            abort(response()->json([
                'message' => "The AI did not mark this student eligible ({$what}). Write a short reason to {$action} anyway.",
            ], 422));
        }
    }

    private function decide(AiRecommendation $r, Request $request, string $status, ?string $note, array $extra = []): void
    {
        $r->update([
            'staff_status' => $status,
            'staff_note' => $note !== null && trim($note) !== '' ? trim($note) : $r->staff_note,
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ] + $extra);
    }

    // POST /staff/recommendations/{id}/invite   Body: note (optional; required when not eligible)
    public function invite(Request $request, $id)
    {
        $this->staffOnly($request);
        $note = $request->validate(['note' => 'nullable|string|max:1000'])['note'] ?? null;

        $r = AiRecommendation::with(['student.user', 'scholarship'])->findOrFail($id);
        $this->needsReason($r, $note, 'invite this student');

        if ($r->applied_status && $r->applied_status !== 'draft') {
            return response()->json(['message' => 'This student already applied for this scholarship.'], 422);
        }

        $log = ApplicationNotifier::invitation($r->student, $r->scholarship, 'invite', null, $note);
        $this->decide($r, $request, 'invited', $note, ['invited_at' => now()]);
        RecommendationLog::record($r, $request->user(), 'invited', $note);
        ActivityLog::record($request->user(), 'recommendation.invited',
            "Sent a scholarship invitation to {$r->student->fullName()} for {$r->scholarship->name} (AI score {$r->score}%).", $r);

        return response()->json([
            'message' => $log && $log->status !== 'failed'
                ? 'Invitation e-mail sent to '.$r->student->fullName().'.'
                : 'Invitation recorded, but the e-mail could not be sent (see Reports → E-mails sent).',
            'recommendation' => $this->present($r->fresh(['student', 'scholarship'])),
        ]);
    }

    /*
    | POST /staff/recommendations/{id}/add   Body: note (optional; required when not eligible)
    | Starts a DRAFT application for the student (after the staff member
    | confirmed). It is not an approval: the student still submits the
    | documents, OAS reviews them, and the agency decides.
    */
    public function add(Request $request, $id)
    {
        $this->staffOnly($request);
        $note = $request->validate(['note' => 'nullable|string|max:1000'])['note'] ?? null;

        $r = AiRecommendation::with(['student.user', 'scholarship'])->findOrFail($id);
        $student = $r->student;
        $scholarship = $r->scholarship;

        if ($scholarship->isAgencyDirect()) {
            return response()->json(['message' => 'Students apply for this program directly at the agency. Use "Send invitation" instead.'], 422);
        }
        if (!$scholarship->is_open) {
            return response()->json(['message' => $scholarship->closedReason()], 422);
        }
        if ($holding = $student->otherActiveScholarship($scholarship->id)) {
            return response()->json(['message' => "{$student->fullName()} already holds {$holding}. Only one active scholarship is allowed."], 422);
        }
        if (Application::where('student_id', $student->id)->where('scholarship_id', $scholarship->id)->exists()) {
            return response()->json(['message' => 'This student already has an application for this scholarship.'], 422);
        }
        $this->needsReason($r, $note, 'add this student');

        $application = DB::transaction(function () use ($student, $scholarship, $request, $r, $note) {
            $application = Application::create([
                'student_id' => $student->id,
                'scholarship_id' => $scholarship->id,
                'status' => 'draft',
                'remarks' => 'Started by the OAS from AI Recommendations. Please attach your documents and submit.',
            ]);

            ApplicationStatusLog::record($application, 'draft',
                'The OAS started this application for you because you may be eligible. Attach your documents and submit.',
                $request->user()->id);

            $this->decide($r, $request, 'added', $note, ['applied_status' => 'draft', 'invited_at' => now()]);
            RecommendationLog::record($r, $request->user(), 'added', $note);

            return $application;
        });

        $log = ApplicationNotifier::invitation($student, $scholarship, 'added', $application, $note);
        ActivityLog::record($request->user(), 'recommendation.added',
            "Started a draft application for {$student->fullName()} ({$scholarship->name}) from AI Recommendations (AI score {$r->score}%).", $application);

        return response()->json([
            'message' => "Draft application started for {$student->fullName()}."
                .($log && $log->status !== 'failed' ? ' The student was e-mailed.' : ' The e-mail could not be sent (see Reports).'),
            'application_id' => $application->id,
            'recommendation' => $this->present($r->fresh(['student', 'scholarship'])),
        ], 201);
    }

    // POST /staff/recommendations/{id}/dismiss   Body: note (optional)
    public function dismiss(Request $request, $id)
    {
        $this->staffOnly($request);
        $note = $request->validate(['note' => 'nullable|string|max:1000'])['note'] ?? null;

        $r = AiRecommendation::with(['student', 'scholarship'])->findOrFail($id);
        $this->decide($r, $request, 'dismissed', $note);
        RecommendationLog::record($r, $request->user(), 'dismissed', $note);
        ActivityLog::record($request->user(), 'recommendation.dismissed',
            "Dismissed the AI recommendation of {$r->student->fullName()} for {$r->scholarship->name}.", $r);

        return response()->json(['message' => 'Recommendation dismissed.', 'recommendation' => $this->present($r->fresh(['student', 'scholarship']))]);
    }

    // POST /staff/recommendations/{id}/wrong   Body: note (required: what the AI got wrong)
    public function wrong(Request $request, $id)
    {
        $this->staffOnly($request);
        $note = $request->validate(['note' => 'required|string|min:5|max:1000'], [
            'note.required' => 'Write what is wrong, so the rule or the data can be fixed.',
        ])['note'];

        $r = AiRecommendation::with(['student', 'scholarship'])->findOrFail($id);
        $this->decide($r, $request, 'wrong', $note);
        RecommendationLog::record($r, $request->user(), 'wrong', $note);
        ActivityLog::record($request->user(), 'recommendation.wrong',
            "Marked the AI recommendation of {$r->student->fullName()} for {$r->scholarship->name} as wrong: {$note}", $r);

        return response()->json(['message' => 'Marked as wrong. It is listed under "Marked wrong" for review.', 'recommendation' => $this->present($r->fresh(['student', 'scholarship']))]);
    }

    // POST /staff/recommendations/{id}/restore
    public function restore(Request $request, $id)
    {
        $this->staffOnly($request);

        $r = AiRecommendation::with(['student', 'scholarship'])->findOrFail($id);
        $r->update(['staff_status' => 'new', 'decided_by' => $request->user()->id, 'decided_at' => now()]);
        RecommendationLog::record($r, $request->user(), 'restored');

        return response()->json(['message' => 'Recommendation restored.', 'recommendation' => $this->present($r->fresh(['student', 'scholarship']))]);
    }

    /*
    | GET /staff/recommendation-logs   (staff and admin; Reports export)
    | include_shown=1 also lists every "shown" row (can be long).
    */
    public function logs(Request $request)
    {
        if (!$request->user()->isOffice()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $showAll = $request->boolean('include_shown');

        return response()->json(
            RecommendationLog::with(['user:id,name', 'student:id,student_id,first_name,last_name', 'scholarship:id,name'])
                ->when(!$showAll, fn ($q) => $q->where('action', '!=', 'shown'))
                ->latest('created_at')->latest('id')
                ->limit(5000)
                ->get()
        );
    }

    /*
    | POST /staff/students/{id}/eligibility   Body: verified (true | false)
    | OAS checked the student's GWA and family income against the Certificate
    | of Grades and the Parents' ITR (or takes the check back).
    */
    public function verifyStudentData(Request $request, $id)
    {
        $this->staffOnly($request);
        $verified = $request->validate(['verified' => 'required|boolean'])['verified'];

        $student = Student::findOrFail($id);

        if ($verified && ($student->gwa === null || $student->family_income === null)) {
            return response()->json(['message' => 'The student has not entered both the GWA and the family income yet.'], 422);
        }

        $student->update([
            'eligibility_verified_at' => $verified ? now() : null,
            'eligibility_verified_by' => $verified ? $request->user()->id : null,
        ]);

        EligibilityEngine::recalculate([$student->id]);
        ActivityLog::record($request->user(), $verified ? 'eligibility.verified' : 'eligibility.unverified',
            ($verified ? 'Verified' : 'Removed the verification of')." the GWA and family income of {$student->fullName()}.", $student);

        return response()->json([
            'message' => $verified ? 'GWA and family income marked as verified.' : 'Verification removed.',
            'student' => $student->fresh(),
        ]);
    }
}
