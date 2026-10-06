<?php

use App\Http\Controllers\AIController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataBankController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ScholarRecordController;
use App\Http\Controllers\ScholarshipController;
use App\Http\Controllers\StudentController;
use Illuminate\Http\Request;
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
    /*
    |--------------------------------------------------------------------------
    | Student
    |--------------------------------------------------------------------------
    */

    Route::get('/profile', [StudentController::class, 'profile']);
    Route::get('/student/history', [StudentController::class, 'history']);

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
    Route::delete('/requirements/{id}', [ScholarshipController::class, 'destroyRequirement']);

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
    Route::post('/applications/{applicationId}/verify-enrollment', [EnrollmentController::class, 'verify']);

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
    Route::post('/scholar-records/{scholarRecordId}/payroll', [PayrollController::class, 'store']);
    Route::patch('/payroll/{id}', [PayrollController::class, 'update']);
});
