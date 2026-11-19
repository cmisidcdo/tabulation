<?php

use App\Http\Controllers\Api\JudgeAppController;
use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('login', [ApiController::class, 'login']);

// Offline judge app (public/judge-app): download an event, score offline, upload later
Route::prefix('judge-app')->group(function () {
    Route::post('login', [JudgeAppController::class, 'login'])->middleware('throttle:10,1');
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [JudgeAppController::class, 'logout']);
        Route::get('events', [JudgeAppController::class, 'events']);
        Route::get('package/{category}', [JudgeAppController::class, 'package']);
        Route::post('upload', [JudgeAppController::class, 'upload'])->middleware('throttle:30,1');
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/upload-database', [ApiController::class, 'saveData']);
    Route::get('/get-reference', [ApiController::class, 'getReference']);
    Route::get('/download-database', [ApiController::class, 'downloadDatabase']);
});
