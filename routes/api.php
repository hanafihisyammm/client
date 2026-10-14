<?php

use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Middleware\BlockWhileImpersonating;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\ReportLinkController;
use App\Http\Controllers\TimeEntryController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Health check endpoints (no auth required for monitoring)
Route::get('/health', [HealthController::class, 'check']);
Route::get('/status', [HealthController::class, 'status']);

// Signed-in users (session) and automations (personal access token, see desk:token).
// The session is tried first, so nothing changes for the browser. The active check is
// repeated here because a token is only resolved by this middleware,
// after the api group's own check has already run.
Route::middleware(['auth:web,sanctum', EnsureUserIsActive::class])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/csrf-token', function () {
        return response()->json(['token' => csrf_token()]);
    });

    // Named api.reports.* so they don't clash with the web pages' reports.* (route:cache refuses duplicates)
    Route::apiResource('reports', ReportController::class)->names('api.reports');

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);

    // Phone / desktop alerts for this browser. A Super Admin signed in as someone else must
    // not start receiving that person's alerts on their own browser.
    Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store'])->middleware(BlockWhileImpersonating::class);
    Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy']);

    Route::get('/projects', function (Request $request) {
        return \App\Models\Project::visibleTo($request->user())
            ->whereHas('reports')
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
        abort_unless($report->isVisibleTo($request->user()), 403, 'Unauthorized');

        $validated = $request->validate([
            'label_ids' => 'required|array',
            'label_ids.*' => 'exists:labels,id',
        ]);

        // Only labels that belong to the report's own project can be attached
        $labelIds = \App\Models\Label::whereIn('id', $validated['label_ids'])
            ->where('project_id', $report->project_id)
            ->pluck('id')
            ->all();
        $report->labels()->sync($labelIds);

        return response()->json([
            'message' => 'Labels updated successfully.',
            'labels' => $report->labels,
        ]);
    });
});
