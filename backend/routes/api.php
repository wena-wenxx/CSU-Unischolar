<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ScholarshipController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ScholarshipRequirementController;
use App\Http\Controllers\ScholarRecordController;
use App\Http\Controllers\PayrollController;

// ---------- Public routes (no login needed) ----------
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// ---------- Protected routes (login required) ----------
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get(
    '/scholars',
    [ScholarRecordController::class, 'index']
);

Route::post(
    '/applications/{applicationId}/create-scholar',
    [ScholarRecordController::class, 'createFromApplication']
);

Route::put(
    '/scholars/{id}/enrollment',
    [ScholarRecordController::class, 'verifyEnrollment']
);

Route::put(
    '/scholars/{id}/atm',
    [ScholarRecordController::class, 'updateAtmStatus']
);

Route::get(
    '/payroll',
    [PayrollController::class, 'index']
);

Route::post(
    '/payroll',
    [PayrollController::class, 'store']
);

    // Scholarships
    Route::get('/scholarships', [ScholarshipController::class, 'index']);
    Route::get('/scholarships/{id}', [ScholarshipController::class, 'show']);
    Route::post('/scholarships', [ScholarshipController::class, 'store']);
    Route::put('/scholarships/{id}', [ScholarshipController::class, 'update']);
    Route::delete('/scholarships/{id}', [ScholarshipController::class, 'destroy']);

    // Scholarship requirements
    Route::get(
        '/scholarships/{scholarshipId}/requirements',
        [ScholarshipRequirementController::class, 'index']
    );

    Route::post(
        '/scholarships/{scholarshipId}/requirements',
        [ScholarshipRequirementController::class, 'store']
    );

    Route::put(
        '/requirements/{id}',
        [ScholarshipRequirementController::class, 'update']
    );

    Route::delete(
        '/requirements/{id}',
        [ScholarshipRequirementController::class, 'destroy']
    );

    Route::put(
    '/applications/{id}/review',
    [ApplicationController::class, 'review']
    );

    // Applications
    Route::post('/applications', [ApplicationController::class, 'store']);
    Route::get('/my-applications', [ApplicationController::class, 'myApplications']);
    Route::get('/applications', [ApplicationController::class, 'index']);
    Route::post('/applications/{id}/documents', [ApplicationController::class, 'uploadDocument']);
    Route::post('/applications/{id}/submit', [ApplicationController::class, 'submit']);
});
