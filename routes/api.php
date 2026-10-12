<?php

use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\TimeEntryController;
use App\Http\Controllers\ReportLinkController;
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

    // Get assignable team members (admins, managers, developers)
    Route::get('/team-members', function () {
        return \App\Models\User::whereIn('role', ['admin', 'super_admin', 'manager', 'developer'])
            ->select('id', 'name', 'email', 'role')
            ->orderBy('name')
            ->get();
    });
    
    Route::get('/projects', function () {
        return \App\Models\Project::whereHas('reports')
            ->orderBy('name')
            ->pluck('name');
    });

    // Project Labels
    Route::get('/projects/{project}/labels', [LabelController::class, 'index']);
    Route::post('/projects/{project}/labels', [LabelController::class, 'store']);
    Route::put('/projects/{project}/labels/{label}', [LabelController::class, 'update']);
    Route::delete('/projects/{project}/labels/{label}', [LabelController::class, 'destroy']);

    // Report Time Entries
    Route::get('/reports/{report}/time-entries', [TimeEntryController::class, 'index']);
    Route::post('/reports/{report}/time-entries', [TimeEntryController::class, 'store']);
    Route::put('/reports/{report}/time-entries/{timeEntry}', [TimeEntryController::class, 'update']);
    Route::delete('/reports/{report}/time-entries/{timeEntry}', [TimeEntryController::class, 'destroy']);

    // Report Links
    Route::get('/reports/{report}/links', [ReportLinkController::class, 'index']);
    Route::post('/reports/{report}/links', [ReportLinkController::class, 'store']);
    Route::delete('/reports/{report}/links/{link}', [ReportLinkController::class, 'destroy']);

    // Report Labels (attach/detach)
    Route::post('/reports/{report}/labels', function (\Illuminate\Http\Request $request, \App\Models\Report $report) {
        $validated = $request->validate([
            'label_ids' => 'required|array',
            'label_ids.*' => 'exists:labels,id',
        ]);
        $report->labels()->sync($validated['label_ids']);
        return response()->json([
            'message' => 'Labels updated successfully.',
            'labels' => $report->labels,
        ]);
    });
});