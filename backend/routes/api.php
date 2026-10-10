<?php

use App\Http\Controllers\AIController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AgencyListController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataBankController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\EnrollmentListController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProfileChangeRequestController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewQueueController;
use App\Http\Controllers\ScholarRecordController;
use App\Http\Controllers\ScholarshipController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentDocumentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::get('/settings/public', [SettingController::class, 'publicSettings']);
    Route::get('/search', [SearchController::class, 'search']);   // top-bar search box

    /*
    |--------------------------------------------------------------------------
    | Student
    |--------------------------------------------------------------------------
    */

    Route::get('/profile', [StudentController::class, 'profile']);
    Route::get('/student/history', [StudentController::class, 'history']);
    Route::patch('/profile', [StudentController::class, 'updateProfile']);
    Route::get('/student/notifications', [StudentController::class, 'notifications']);
    Route::post('/student/notifications/read', [StudentController::class, 'markNotificationsRead']);
    Route::get('/student/change-requests', [StudentController::class, 'changeRequests']);
    Route::post('/student/change-requests', [StudentController::class, 'storeChangeRequest']);

    // Contact OAS (students write, staff reply)
    Route::get('/student/messages', [ContactController::class, 'mine']);
    Route::post('/student/messages', [ContactController::class, 'store']);
    Route::get('/staff/messages', [ContactController::class, 'index']);
    Route::post('/staff/messages/{id}/reply', [ContactController::class, 'reply']);
    Route::post('/staff/messages/{id}/status', [ContactController::class, 'setStatus']);

    // My Documents: every file the student uploaded, reusable across applications
    Route::get('/student/documents', [StudentDocumentController::class, 'index']);
    Route::post('/student/documents', [StudentDocumentController::class, 'store']);
    Route::post('/student/documents/{id}/replace', [StudentDocumentController::class, 'replace']);
    Route::post('/applications/{id}/documents/reuse', [ApplicationController::class, 'reuseDocument']);

    /*
    |--------------------------------------------------------------------------
    | Scholarships
    |--------------------------------------------------------------------------
    */

    Route::get('/scholarships', [ScholarshipController::class, 'index']);
    Route::get('/scholarships/{id}', [ScholarshipController::class, 'show']);
    Route::post('/scholarships', [ScholarshipController::class, 'store']);
    Route::put('/scholarships/{id}', [ScholarshipController::class, 'update']);
    Route::delete('/scholarships/{id}', [ScholarshipController::class, 'destroy']);
    Route::post('/scholarships/{id}/requirements', [ScholarshipController::class, 'addRequirement']);
    Route::patch('/requirements/{id}', [ScholarshipController::class, 'updateRequirement']);
    Route::delete('/requirements/{id}', [ScholarshipController::class, 'destroyRequirement']);
    Route::get('/requirement-types', [ScholarshipController::class, 'requirementTypes']);

    /*
    |--------------------------------------------------------------------------
    | Applications
    |--------------------------------------------------------------------------
    */

    Route::post('/applications', [ApplicationController::class, 'store']);
    Route::get('/my-applications', [ApplicationController::class, 'myApplications']);
    Route::get('/applications', [ApplicationController::class, 'index']);
    Route::get('/applications/{id}', [ApplicationController::class, 'show']);
    Route::post('/applications/{id}/submit', [ApplicationController::class, 'submit']);
    Route::patch('/applications/{id}/review', [ApplicationController::class, 'review']);

    // Auto-Review: suggestions only; staff press Forward / Send back
    Route::post('/staff/auto-review', [ReviewQueueController::class, 'run']);
    Route::post('/staff/applications/forward', [ReviewQueueController::class, 'forward']);
    Route::post('/staff/applications/needs-action', [ReviewQueueController::class, 'needsAction']);
    Route::post('/applications/{applicationId}/verify-enrollment', [EnrollmentController::class, 'verify']);

    // Enrollment page: Registrar list + Verify All Enrollments
    Route::get('/staff/enrollment-lists', [EnrollmentListController::class, 'index']);
    Route::post('/staff/enrollment-lists', [EnrollmentListController::class, 'store']);
    Route::post('/staff/enrollment/check', [EnrollmentListController::class, 'check']);
    Route::post('/staff/enrollment/record', [EnrollmentListController::class, 'record']);

    /*
    |--------------------------------------------------------------------------
    | Staff Dashboard
    |--------------------------------------------------------------------------
    */

    Route::get('/staff/dashboard', [DashboardController::class, 'index']);

    /*
    |--------------------------------------------------------------------------
    | Scholarship Data Bank (staff): search a student, see full history
    |--------------------------------------------------------------------------
    */

    Route::get('/staff/data-bank', [DataBankController::class, 'search']);
    Route::get('/staff/data-bank/{studentId}', [DataBankController::class, 'show']);
    Route::get('/staff/profile-requests', [ProfileChangeRequestController::class, 'index']);
    Route::patch('/staff/profile-requests/{id}', [ProfileChangeRequestController::class, 'resolve']);
    Route::get('/staff/agency-lists', [AgencyListController::class, 'index']);
    Route::post('/staff/agency-lists', [AgencyListController::class, 'store']);
    Route::get('/staff/email-logs', [ReportController::class, 'emailLogs']);

    /*
    |--------------------------------------------------------------------------
    | Announcements (students read; staff manage)
    |--------------------------------------------------------------------------
    */

    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::post('/announcements', [AnnouncementController::class, 'store']);
    Route::get('/announcements/{id}', [AnnouncementController::class, 'show']);
    // POST with _method=PUT is used when a picture is sent (file uploads need POST).
    Route::put('/announcements/{id}', [AnnouncementController::class, 'update']);
    Route::delete('/announcements/{id}', [AnnouncementController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    */

    Route::post('/applications/{id}/documents', [ApplicationController::class, 'uploadDocument']);
    Route::post('/documents/{documentId}/validate', [AIController::class, 'validateDocument']);

    /*
    |--------------------------------------------------------------------------
    | Scholar Records (grantee tagging)
    |--------------------------------------------------------------------------
    */

    Route::get('/scholar-records', [ScholarRecordController::class, 'index']);
    Route::get('/scholar-records/{id}', [ScholarRecordController::class, 'show']);
    Route::post('/applications/{applicationId}/scholar-record', [ScholarRecordController::class, 'store']);
    Route::patch('/scholar-records/{id}', [ScholarRecordController::class, 'update']);

    /*
    |--------------------------------------------------------------------------
    | Payroll
    |--------------------------------------------------------------------------
    */

    Route::get('/payroll', [PayrollController::class, 'index']);
    Route::get('/payroll/periods', [PayrollController::class, 'periods']);
    Route::get('/payroll/history', [PayrollController::class, 'history']);
    Route::post('/payroll/prepare', [PayrollController::class, 'prepare']);
    Route::post('/payroll/bulk-status', [PayrollController::class, 'bulkStatus']);
    Route::post('/scholar-records/{scholarRecordId}/payroll', [PayrollController::class, 'store']);
    Route::patch('/payroll/{id}', [PayrollController::class, 'update']);

    /*
    |--------------------------------------------------------------------------
    | System administrator: accounts, activity log, settings
    |--------------------------------------------------------------------------
    */

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
