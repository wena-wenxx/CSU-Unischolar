<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Document;
use App\Models\PayrollRecord;
use App\Models\ProfileChangeRequest;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $apps = Application::with(['scholarship.requirements', 'documents'])->get();

        $totalApplicants = $apps->pluck('student_id')->unique()->count();
        $totalApplications = $apps->count();
        $needsAction = $apps->where('status', 'needs_action')->count();
        $approved = $apps->where('status', 'approved')->count();
        $activeScholars = ScholarRecord::where('status', 'active')->count();
        $payrollReady = PayrollRecord::where('status', 'ready')->count();
        $aiFlags = Document::whereNotNull('application_id')->where('status', 'flagged')->count(); // only documents in applications
        $scholarships = Scholarship::count();

        return response()->json([

            // Field names the staff dashboard in App.jsx reads.
            'total_applicants' => $totalApplicants,
            'total_applications' => $totalApplications,
            'needs_action' => $needsAction,
            'approved' => $approved,
            'active_scholars' => $activeScholars,
            'payroll_ready' => $payrollReady,
            'ai_flags' => $aiFlags,
            // Applications with at least one flagged document (the "Has AI flags" list).
            'applications_with_flags' => $apps->filter(fn ($a) => $a->documents->contains('status', 'flagged'))->count(),
            'scholarships' => $scholarships,

            // Extra detail, kept for reports and future screens.
            'applications_by_status' => $apps->groupBy('status')->map->count(),
            'applications_with_missing_docs' => $apps->filter(fn ($a) => count($a->missingRequirementNames()) > 0)->count(),
            'documents_needing_review' => Document::whereNotNull('application_id')->whereIn('status', ['flagged', 'needs_review'])->count(),
            'students_needing_review' => $apps->whereIn('status', ['submitted', 'under_review', 'needs_action'])->pluck('student_id')->unique()->count(),
            'currently_enrolled_grantees' => ScholarRecord::where('status', 'active')->where('currently_enrolled', true)->count(),
            'students_with_active_scholarship' => ScholarRecord::where('status', 'active')->distinct()->count('student_id'),
            'active_scholarship_programs' => Scholarship::where('status', 'active')->count(),
            // Most recent activity first (any step: submitted, reviewed, verified...).
            'recent_applications' => Application::with(['student', 'scholarship:id,name', 'latestLog'])
                ->where('status', '!=', 'draft')
                ->orderByDesc('updated_at')->orderByDesc('id')
                ->limit(6)->get(),
            'pending_profile_requests' => ProfileChangeRequest::where('status', 'pending')->count(),

            // Older names for the same numbers (backward compatibility).
            'approved_applications' => $approved,
            'active_grantees' => $activeScholars,
            'ai_flagged_documents' => $aiFlags,
            'scholarship_programs' => $scholarships,
        ]);
    }
}
