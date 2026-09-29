<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Document;
use App\Models\PayrollRecord;
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
        $activeGrantees = ScholarRecord::where('status', 'active');

        return response()->json([
            'total_applicants' => $apps->pluck('student_id')->unique()->count(),
            'total_applications' => $apps->count(),
            'applications_by_status' => $apps->groupBy('status')->map->count(),
            'applications_with_missing_docs' => $apps->filter(fn ($a) => count($a->missingRequirementNames()) > 0)->count(),
            'ai_flagged_documents' => Document::where('status', 'flagged')->count(),
            'documents_needing_review' => Document::whereIn('status', ['flagged', 'needs_review'])->count(),
            'students_needing_review' => $apps->whereIn('status', ['submitted', 'under_review', 'needs_action'])->pluck('student_id')->unique()->count(),
            'approved_applications' => $apps->where('status', 'approved')->count(),
            'active_grantees' => (clone $activeGrantees)->count(),
            'currently_enrolled_grantees' => (clone $activeGrantees)->where('currently_enrolled', true)->count(),
            'students_with_active_scholarship' => (clone $activeGrantees)->distinct()->count('student_id'),
            'payroll_ready' => PayrollRecord::where('status', 'ready')->count(),
            'scholarship_programs' => Scholarship::count(),
            'active_scholarship_programs' => Scholarship::where('status', 'active')->count(),
            'recent_applications' => Application::with(['student', 'scholarship'])->orderBy('id', 'desc')->limit(5)->get(),
        ]);
    }
}
