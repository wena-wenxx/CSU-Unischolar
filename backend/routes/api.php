<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataBankController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ScholarRecordController;
use App\Http\Controllers\ScholarshipController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ---------- Public routes ----------
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// ---------- Protected routes ----------
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', fn (Request $request) => $request->user());

    // Scholarships
    Route::get('/scholarships', [ScholarshipController::class, 'index']);
    Route::get('/scholarships/{id}', [ScholarshipController::class, 'show']);
    Route::post('/scholarships', [ScholarshipController::class, 'store']);
    Route::put('/scholarships/{id}', [ScholarshipController::class, 'update']);
    Route::delete('/scholarships/{id}', [ScholarshipController::class, 'destroy']);

    // Scholarship requirements
    Route::get('/scholarships/{scholarshipId}/requirements', [ScholarshipController::class, 'listRequirements']);
    Route::post('/scholarships/{id}/requirements', [ScholarshipController::class, 'addRequirement']);
    Route::put('/requirements/{id}', [ScholarshipController::class, 'updateRequirement']);
    Route::delete('/requirements/{id}', [ScholarshipController::class, 'destroyRequirement']);

    // Applications
    Route::post('/applications', [ApplicationController::class, 'store']);
    Route::get('/my-applications', [ApplicationController::class, 'myApplications']);
    Route::get('/applications', [ApplicationController::class, 'index']);
    Route::get('/applications/{id}', [ApplicationController::class, 'show']);
    Route::post('/applications/{id}/submit', [ApplicationController::class, 'submit']);
    Route::put('/applications/{id}/review', [ApplicationController::class, 'review']);
    Route::post('/applications/{id}/documents', [ApplicationController::class, 'uploadDocument']);

    // Documents (AI check + human review)
    Route::post('/documents/{id}/revalidate', [ApplicationController::class, 'revalidateDocument']);
    Route::patch('/documents/{id}/review', [ApplicationController::class, 'reviewDocument']);

    // Scholar records (grantees)
    Route::get('/scholars', [ScholarRecordController::class, 'index']);
    Route::post('/applications/{applicationId}/create-scholar', [ScholarRecordController::class, 'createFromApplication']);
    Route::put('/scholars/{id}/enrollment', [ScholarRecordController::class, 'verifyEnrollment']);
    Route::put('/scholars/{id}/atm', [ScholarRecordController::class, 'updateAtmStatus']);
    Route::put('/scholars/{id}', [ScholarRecordController::class, 'update']);
    Route::get('/my-history', [ScholarRecordController::class, 'mine']);

    // Centralized data bank
    Route::get('/data-bank/search', [DataBankController::class, 'search']);
    Route::get('/data-bank/students/{id}', [DataBankController::class, 'show']);

    // Payroll-ready preparation (export route must come before {id})
    Route::get('/payroll/export', [PayrollController::class, 'export']);
    Route::get('/payroll', [PayrollController::class, 'index']);
    Route::post('/payroll', [PayrollController::class, 'store']);
    Route::post('/payroll/generate', [PayrollController::class, 'generate']);
    Route::patch('/payroll/{id}', [PayrollController::class, 'update']);

    // Staff dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);
});
