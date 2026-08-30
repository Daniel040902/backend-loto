<?php

use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [App\Http\Controllers\Api\AuthController::class, 'register']);
    Route::post('login', [App\Http\Controllers\Api\AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [App\Http\Controllers\Api\AuthController::class, 'logout']);
        Route::get('me', [App\Http\Controllers\Api\AuthController::class, 'me']);
        Route::post('change-password', [App\Http\Controllers\Api\AuthController::class, 'changePassword']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('countries', [App\Http\Controllers\Api\CountryController::class, 'index']);
    Route::get('countries/{slug}', [App\Http\Controllers\Api\CountryController::class, 'show']);

    Route::get('results', [App\Http\Controllers\Api\ResultController::class, 'index']);
    Route::get('results/latest', [App\Http\Controllers\Api\ResultController::class, 'latest']);
    Route::get('results/compare/{country}', [App\Http\Controllers\Api\ResultController::class, 'compare']);
    Route::get('results/{country}', [App\Http\Controllers\Api\ResultController::class, 'byCountry']);
    Route::post('results', [App\Http\Controllers\Api\ResultController::class, 'store']);

    Route::post('devices', [App\Http\Controllers\Api\DeviceController::class, 'register']);
    Route::delete('devices', [App\Http\Controllers\Api\DeviceController::class, 'unregister']);

    Route::get('dashboard', [App\Http\Controllers\Api\DashboardController::class, 'index']);
});
Route::get('check', [App\Http\Controllers\Api\CheckController::class, 'check']);
