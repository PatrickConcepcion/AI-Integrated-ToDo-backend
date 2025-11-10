<?php

use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/refresh', [AuthController::class, 'refresh']);

// Protected routes (JWT middleware)
Route::middleware('auth:api')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Tasks endpoints
    Route::apiResource('tasks', \App\Http\Controllers\TaskController::class);
    
    Route::prefix('tasks')->group(function () {
        Route::get('/archived', [\App\Http\Controllers\TaskController::class, 'archived']);
        Route::prefix('{task}')->group(function () {
            Route::post('/complete', [\App\Http\Controllers\TaskController::class, 'toggleComplete']);
            Route::post('/archive', [\App\Http\Controllers\TaskController::class, 'archive']);
            Route::post('/unarchive', [\App\Http\Controllers\TaskController::class, 'unarchive']);
        });
    });

    Route::apiResource('categories', \App\Http\Controllers\CategoryController::class)->only(['index', 'show']);
});

// Admin only routes
Route::middleware(['auth:api', 'role:admin'])->group(function () {
    Route::apiResource('categories', \App\Http\Controllers\CategoryController::class)->only(['store', 'update', 'destroy']);

    Route::post('/ai/chat', function () {
        return response()->json(['message' => 'AI chat endpoint - to be implemented']);
    });
});
