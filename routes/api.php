<?php

use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Health check endpoints (no auth required for monitoring)
Route::get('/health', [HealthController::class, 'check']);
Route::get('/status', [HealthController::class, 'status']);

Route::middleware('auth')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    
    Route::get('/csrf-token', function () {
        return response()->json(['token' => csrf_token()]);
    });

    Route::apiResource('reports', ReportController::class);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    
    Route::get('/developers', function () {
        return \App\Models\User::where('role', 'admin')
            ->select('id', 'name', 'email')
            ->get();
    });
    
    Route::get('/projects', function () {
        return \App\Models\Project::whereHas('reports')
            ->orderBy('name')
            ->pluck('name');
    });
});