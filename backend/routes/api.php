<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ScholarRecordController;
use App\Http\Controllers\ScholarshipController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AIController;


/*
|--------------------------------------------------------------------------
| PUBLIC
|--------------------------------------------------------------------------
*/

Route::post('/register', [AuthController::class, 'register']);

Route::post('/login', [AuthController::class, 'login']);


/*
|--------------------------------------------------------------------------
| AUTHENTICATED
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | AUTH
    |--------------------------------------------------------------------------
    */

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/user', [AuthController::class, 'me']);


    Route::post('/documents/{documentId}/validate', [AIController::class, 'validateDocument']);


    /*
    |--------------------------------------------------------------------------
    | SCHOLARSHIPS
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/scholarships',
        [ScholarshipController::class, 'index']
    );

    Route::get(
        '/scholarships/{id}',
        [ScholarshipController::class, 'show']
    );

    Route::post(
        '/scholarships',
        [ScholarshipController::class, 'store']
    );

    Route::put(
        '/scholarships/{id}',
        [ScholarshipController::class, 'update']
    );

    Route::delete(
        '/scholarships/{id}',
        [ScholarshipController::class, 'destroy']
    );

    Route::post(
        '/scholarships/{id}/requirements',
        [ScholarshipController::class, 'addRequirement']
    );


    /*
    |--------------------------------------------------------------------------
    | APPLICATIONS
    |--------------------------------------------------------------------------
    */

    // Student
    Route::post(
        '/applications',
        [ApplicationController::class, 'store']
    );

    Route::get(
        '/my-applications',
        [ApplicationController::class, 'myApplications']
    );

    Route::post(
        '/applications/{id}/submit',
        [ApplicationController::class, 'submit']
    );

    // Student + staff
    Route::post(
        '/applications/{id}/documents',
        [ApplicationController::class, 'uploadDocument']
    );

    // Staff
    Route::get(
        '/applications',
        [ApplicationController::class, 'index']
    );

    Route::get(
        '/applications/{id}',
        [ApplicationController::class, 'show']
    );

    Route::put(
        '/applications/{id}/review',
        [ApplicationController::class, 'review']
    );


    /*
    |--------------------------------------------------------------------------
    | ENROLLMENT
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/applications/{applicationId}/enrollment',
        [EnrollmentController::class, 'verify']
    );


    /*
    |--------------------------------------------------------------------------
    | SCHOLAR RECORDS
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/scholar-records',
        [ScholarRecordController::class, 'index']
    );

    Route::post(
        '/scholar-records',
        [ScholarRecordController::class, 'store']
    );

    Route::put(
        '/scholar-records/{id}',
        [ScholarRecordController::class, 'update']
    );

    Route::get(
        '/my-scholar-records',
        [ScholarRecordController::class, 'myRecords']
    );


    /*
    |--------------------------------------------------------------------------
    | PAYROLL
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/payroll',
        [PayrollController::class, 'index']
    );

    Route::post(
        '/payroll',
        [PayrollController::class, 'store']
    );

    Route::put(
        '/payroll/{id}',
        [PayrollController::class, 'update']
    );

    Route::get(
        '/payroll/ready',
        [PayrollController::class, 'ready']
    );
});