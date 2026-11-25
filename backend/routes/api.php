<?php

use App\Http\Controllers\AIController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AgencyListController;
use App\Http\Controllers\ProcessingBatchController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataBankController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\EnrollmentListController;
use App\Http\Controllers\ExternalGranteeController;
use App\Http\Controllers\ForwardedExportController;
use App\Http\Controllers\ProfilePhotoController;
use App\Http\Controllers\AiRulesController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProfileChangeRequestController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewQueueController;
use App\Http\Controllers\ScholarRecordController;
use App\Http\Controllers\ScholarshipController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentDocumentController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::get('/office-contact', [SettingController::class, 'officeContact']);

Route::middleware(['auth:sanctum', \App\Http\Middleware\RequirePasswordChange::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::post('/profile/photo', [ProfilePhotoController::class, 'store']);
    Route::delete('/profile/photo', [ProfilePhotoController::class, 'destroy']);
    Route::get('/settings/public', [SettingController::class, 'publicSettings']);
    Route::get('/search', [SearchController::class, 'search']);

    Route::get('/profile', [StudentController::class, 'profile']);
    Route::get('/student/history', [StudentController::class, 'history']);
    Route::get('/student/matches', [StudentController::class, 'matches']);
    Route::patch('/profile', [StudentController::class, 'updateProfile']);
    Route::patch('/profile/eligibility', [StudentController::class, 'updateEligibility']);
    Route::patch('/profile/interests', [StudentController::class, 'updateInterests']);
    Route::get('/student/notifications', [StudentController::class, 'notifications']);
    Route::post('/student/notifications/read', [StudentController::class, 'markNotificationsRead']);
    Route::get('/student/change-requests', [StudentController::class, 'changeRequests']);
    Route::post('/student/change-requests', [StudentController::class, 'storeChangeRequest']);

    Route::get('/student/messages', [ContactController::class, 'mine']);
    Route::post('/student/messages', [ContactController::class, 'store']);
    Route::get('/staff/messages', [ContactController::class, 'index']);
    Route::get('/staff/alerts', [\App\Http\Controllers\StaffAlertController::class, 'index']);
    Route::post('/staff/alerts/seen', [\App\Http\Controllers\StaffAlertController::class, 'seen']);
    Route::post('/staff/messages/{id}/reply', [ContactController::class, 'reply']);
    Route::post('/staff/messages/{id}/status', [ContactController::class, 'setStatus']);

    Route::get('/student/documents', [StudentDocumentController::class, 'index']);
    Route::post('/student/documents', [StudentDocumentController::class, 'store']);
    Route::post('/student/documents/{id}/replace', [StudentDocumentController::class, 'replace']);
    Route::delete('/student/documents/{id}', [StudentDocumentController::class, 'destroy'])->whereNumber('id');
    Route::post('/applications/{id}/documents/reuse', [ApplicationController::class, 'reuseDocument']);

    Route::get('/scholarships', [ScholarshipController::class, 'index']);
    Route::get('/scholarships/{id}', [ScholarshipController::class, 'show']);
    Route::post('/scholarships', [ScholarshipController::class, 'store']);
    Route::put('/scholarships/{id}', [ScholarshipController::class, 'update']);
    Route::delete('/scholarships/{id}', [ScholarshipController::class, 'destroy']);
    Route::post('/scholarships/{id}/requirements', [ScholarshipController::class, 'addRequirement']);
    Route::patch('/requirements/{id}', [ScholarshipController::class, 'updateRequirement']);
    Route::delete('/requirements/{id}', [ScholarshipController::class, 'destroyRequirement']);
    Route::get('/requirement-types', [ScholarshipController::class, 'requirementTypes']);
    Route::get('/eligibility-options', [ScholarshipController::class, 'eligibilityOptions']);

    Route::post('/applications', [ApplicationController::class, 'store']);
    Route::get('/my-applications', [ApplicationController::class, 'myApplications']);
    Route::get('/applications', [ApplicationController::class, 'index']);
    Route::get('/staff/applications/version', [ApplicationController::class, 'version']);
    Route::get('/applications/{id}', [ApplicationController::class, 'show']);
    Route::post('/applications/{id}/submit', [ApplicationController::class, 'submit']);
    Route::patch('/applications/{id}/review', [ApplicationController::class, 'review']);

    Route::post('/staff/auto-review', [ReviewQueueController::class, 'run']);
    Route::post('/staff/applications/forward', [ReviewQueueController::class, 'forward']);
    Route::get('/staff/applications/{id}/suggested-remark', [ReviewQueueController::class, 'suggestedRemark']);
    Route::post('/staff/applications/needs-action', [ReviewQueueController::class, 'needsAction']);
    Route::post('/applications/{applicationId}/verify-enrollment', [EnrollmentController::class, 'verify']);

    Route::get('/staff/enrollment-lists', [EnrollmentListController::class, 'index']);
    Route::post('/staff/enrollment-lists', [EnrollmentListController::class, 'store']);
    Route::get('/staff/enrollment-lists/{id}/rows', [EnrollmentListController::class, 'rows'])->whereNumber('id');
    Route::post('/staff/enrollment/check', [EnrollmentListController::class, 'check']);
    Route::post('/staff/enrollment/record', [EnrollmentListController::class, 'record']);

    Route::get('/staff/dashboard', [DashboardController::class, 'index']);

    Route::get('/staff/data-bank', [DataBankController::class, 'search']);
    Route::get('/staff/data-bank/{studentId}', [DataBankController::class, 'show']);
    Route::get('/staff/profile-requests', [ProfileChangeRequestController::class, 'index']);
    Route::patch('/staff/profile-requests/{id}', [ProfileChangeRequestController::class, 'resolve']);
    Route::get('/staff/agency-lists', [AgencyListController::class, 'index']);
    Route::post('/staff/agency-lists', [AgencyListController::class, 'store']);
    Route::get('/staff/agency-lists/{id}', [AgencyListController::class, 'show']);
    Route::post('/staff/agency-lists/{id}/done', [AgencyListController::class, 'markDone']);
    Route::post('/staff/agency-lists/{id}/rows/{row}/link', [AgencyListController::class, 'linkRow']);
    Route::post('/staff/agency-lists/{id}/set-aside', [AgencyListController::class, 'setAsideRows']);
    Route::post('/staff/agency-lists/{id}/rows/{row}/resume', [AgencyListController::class, 'resumeRow']);
    Route::post('/staff/agency-lists/{id}/rows/{row}/set-aside', [AgencyListController::class, 'setAsideRow']);

    Route::get('/staff/processing-batches', [ProcessingBatchController::class, 'index']);
    Route::post('/staff/processing-batches', [ProcessingBatchController::class, 'store']);
    Route::get('/staff/processing-batches/{id}', [ProcessingBatchController::class, 'show']);
    Route::patch('/staff/processing-batches/{id}', [ProcessingBatchController::class, 'update']);
    Route::post('/staff/processing-batches/{id}/complete', [ProcessingBatchController::class, 'complete']);
    Route::post('/staff/processing-batches/{id}/reopen', [ProcessingBatchController::class, 'reopen']);
    Route::get('/staff/mail-mode', function (\Illuminate\Http\Request $request) {
        if (!in_array($request->user()->role, ['staff', 'admin'], true)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json(\App\Support\MailMode::describe());
    });
    Route::get('/staff/email-logs', [ReportController::class, 'emailLogs']);

    Route::get('/staff/recommendations', [RecommendationController::class, 'index']);
    Route::post('/staff/recommendations/recalculate', [RecommendationController::class, 'recalculate']);
    Route::post('/staff/recommendations/notifications/check', [RecommendationController::class, 'checkNotifications']);
    Route::post('/staff/recommendations/notifications', [RecommendationController::class, 'sendNotifications']);
    Route::get('/staff/recommendations/{id}', [RecommendationController::class, 'show']);
    Route::post('/staff/recommendations/{id}/invite', [RecommendationController::class, 'invite']);
    Route::get('/staff/notices', [RecommendationController::class, 'sentNotices']);
    Route::get('/staff/invitation-activity', [RecommendationController::class, 'activity']);
    Route::post('/staff/recommendations/{id}/add', [RecommendationController::class, 'add']);
    Route::post('/staff/recommendations/{id}/dismiss', [RecommendationController::class, 'dismiss']);
    Route::post('/staff/recommendations/{id}/wrong', [RecommendationController::class, 'wrong']);
    Route::post('/staff/recommendations/{id}/restore', [RecommendationController::class, 'restore']);
    Route::get('/staff/recommendation-logs', [RecommendationController::class, 'logs']);
    Route::post('/staff/students/{id}/eligibility', [RecommendationController::class, 'verifyStudentData']);

    Route::get('/staff/external-grantees', [ExternalGranteeController::class, 'index']);
    Route::patch('/staff/external-grantees/{id}', [ExternalGranteeController::class, 'update']);
    Route::get('/staff/external-grantees/uploads', [ExternalGranteeController::class, 'uploads']);
    Route::post('/staff/external-grantees/uploads', [ExternalGranteeController::class, 'store']);
    Route::get('/staff/external-grantees/uploads/{id}', [ExternalGranteeController::class, 'showUpload']);
    Route::get('/staff/external-grantees/uploads/{id}/file', [ExternalGranteeController::class, 'download']);
    Route::post('/staff/external-grantees/uploads/{id}/save', [ExternalGranteeController::class, 'save']);
    Route::post('/staff/external-grantees/uploads/{id}/discard', [ExternalGranteeController::class, 'discard']);
    Route::post('/staff/read-table', [ExternalGranteeController::class, 'readTable']);
    Route::post('/staff/forwarded/export', [ForwardedExportController::class, 'export']);
    Route::get('/staff/agency-submissions', [\App\Http\Controllers\AgencySubmissionController::class, 'index']);
    Route::post('/staff/agency-submissions', [\App\Http\Controllers\AgencySubmissionController::class, 'store']);
    Route::post('/staff/forwarded/documents-zip', [\App\Http\Controllers\AgencySubmissionController::class, 'documentsZip']);
    Route::post('/staff/billing-statement', [\App\Http\Controllers\BillingStatementController::class, 'export']);
    Route::post('/staff/ai/suggest-rules', [AiRulesController::class, 'suggest']);

    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::post('/announcements', [AnnouncementController::class, 'store']);
    Route::get('/announcements/{id}', [AnnouncementController::class, 'show']);
    Route::put('/announcements/{id}', [AnnouncementController::class, 'update']);
    Route::delete('/announcements/{id}', [AnnouncementController::class, 'destroy']);

    Route::post('/applications/{id}/documents', [ApplicationController::class, 'uploadDocument']);
    Route::post('/documents/{documentId}/validate', [AIController::class, 'validateDocument']);
    Route::get('/documents/{documentId}/check-status', [AIController::class, 'checkStatus']);
    Route::post('/documents/{documentId}/cancel-check', [AIController::class, 'cancelCheck']);
    Route::post('/documents/{documentId}/verify', [AIController::class, 'verify']);

    Route::get('/scholar-records', [ScholarRecordController::class, 'index']);
    Route::get('/scholar-records/{id}', [ScholarRecordController::class, 'show']);
    Route::post('/applications/{applicationId}/scholar-record', [ScholarRecordController::class, 'store']);
    Route::patch('/scholar-records/{id}', [ScholarRecordController::class, 'update']);

    Route::get('/payroll', [PayrollController::class, 'index']);
    Route::get('/payroll/periods', [PayrollController::class, 'periods']);
    Route::get('/payroll/history', [PayrollController::class, 'history']);
    Route::post('/payroll/prepare', [PayrollController::class, 'prepare']);
    Route::post('/payroll/bulk-status', [PayrollController::class, 'bulkStatus']);
    Route::post('/payroll/form', [PayrollController::class, 'form']);
    Route::post('/payroll/form/docx', [PayrollController::class, 'formDocx']);
    Route::post('/payroll/form/pdf', [PayrollController::class, 'formPdf']);
    Route::post('/scholar-records/{scholarRecordId}/payroll', [PayrollController::class, 'store']);
    Route::patch('/payroll/{id}', [PayrollController::class, 'update']);

    Route::get('/admin/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/admin/users', [AdminController::class, 'users']);
    Route::post('/admin/users', [AdminController::class, 'store']);
    Route::patch('/admin/users/{id}', [AdminController::class, 'update']);
    Route::post('/admin/users/{id}/reset-password', [AdminController::class, 'resetPassword']);
    Route::post('/admin/users/{id}/active', [AdminController::class, 'setActive']);
    Route::get('/admin/activity', [AdminController::class, 'activity']);
    Route::get('/admin/settings', [AdminController::class, 'settings']);
    Route::put('/admin/settings', [AdminController::class, 'updateSettings']);
});

Route::get('/files/documents/{id}', [\App\Http\Controllers\DocumentFileController::class, 'show'])
    ->middleware(['signed', 'throttle:120,1'])
    ->name('documents.file');
