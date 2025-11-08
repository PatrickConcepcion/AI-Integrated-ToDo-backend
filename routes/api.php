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

// Protected routes (JWT middleware)
Route::middleware('auth:api')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Tasks endpoints
    Route::apiResource('tasks', \App\Http\Controllers\TaskController::class);
    Route::get('/tasks-archived', [\App\Http\Controllers\TaskController::class, 'archived']);
    Route::post('/tasks/{task}/complete', [\App\Http\Controllers\TaskController::class, 'toggleComplete']);
    Route::post('/tasks/{task}/archive', [\App\Http\Controllers\TaskController::class, 'archive']);
    Route::post('/tasks/{task}/unarchive', [\App\Http\Controllers\TaskController::class, 'unarchive']);

    // Categories endpoints
    Route::apiResource('categories', \App\Http\Controllers\CategoryController::class);
});

// Token refresh: allow expired tokens to request a new one
// This route must NOT be behind 'auth:api' so that expired tokens can be refreshed
Route::post('/auth/refresh', [AuthController::class, 'refresh']);

// Admin only routes
Route::middleware(['auth:api', 'admin'])->group(function () {
    Route::post('/ai/chat', function () {
        return response()->json(['message' => 'AI chat endpoint - to be implemented']);
    });
});
