<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\AiChatController;
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
    
    // Specific task routes MUST come before apiResource to avoid conflicts
    Route::prefix('tasks')->group(function () {
        Route::get('archived', [TaskController::class, 'archived']);
        
        Route::prefix('{task}')->group(function () {
            Route::post('complete', [TaskController::class, 'toggleComplete']);
            Route::post('archive', [TaskController::class, 'archive']);
            Route::post('unarchive', [TaskController::class, 'unarchive']);
        });
    });

    Route::apiResource('tasks', TaskController::class);
    Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);

    // AI Assistant - available to all authenticated users
    Route::post('/ai/chat', [AiChatController::class, 'chat']);
    Route::get('/ai/messages', [AiChatController::class, 'getMessages']);
    Route::delete('/ai/conversations', [AiChatController::class, 'clearConversation']);
});

// Admin only routes
Route::middleware(['auth:api', 'role:admin'])->group(function () {
    Route::apiResource('categories', \App\Http\Controllers\CategoryController::class)->only(['store', 'update', 'destroy']);
    Route::get('/admin/users', [AuthController::class, 'index']);
    Route::post('/admin/users/{user}/ban', [AuthController::class, 'banUser']);
    Route::post('/admin/users/{user}/unban', [AuthController::class, 'unbanUser']);
});
