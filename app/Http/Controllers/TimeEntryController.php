<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\TimeEntry;
use App\Models\ReportActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TimeEntryController extends Controller
{
    public function index(Report $report)
    {
        $user = Auth::user();

        if (!$report->isVisibleTo($user)) {
            abort(403, 'Unauthorized');
        }

        $timeEntries = $report->timeEntries()
            ->with('user:id,name,email')
            ->get();

        $totalHours = $report->getTotalTimeSpent();

        return response()->json([
            'time_entries' => $timeEntries,
            'total_hours' => $totalHours,
            'estimated_hours' => $report->estimated_hours,
        ]);
    }

    public function store(Request $request, Report $report)
    {
        $user = Auth::user();

        if (!$report->isVisibleTo($user)) {
            abort(403, 'Unauthorized');
        }


        $validated = $request->validate([
            'hours' => 'required|numeric|min:0.25|max:24',
            'description' => 'nullable|string|max:500',
            'logged_date' => 'required|date|before_or_equal:' . Report::businessToday(),
        ]);

        $timeEntry = $report->timeEntries()->create([
            'user_id' => $user->id,
            'hours' => $validated['hours'],
            'description' => $validated['description'] ?? null,
            'logged_date' => $validated['logged_date'],
        ]);

        // Log activity
        ReportActivityLog::create([
            'report_id' => $report->id,
            'user_id' => $user->id,
            'action' => 'logged_time',
            'new_values' => [
                'hours' => $validated['hours'],
                'description' => $validated['description'] ?? null,
            ],
        ]);

        $timeEntry->load('user:id,name,email');

        return response()->json([
            'message' => 'Time logged successfully.',
            'time_entry' => $timeEntry,
            'total_hours' => $report->getTotalTimeSpent(),
        ], 201);
    }

    public function update(Request $request, Report $report, TimeEntry $timeEntry)
    {
        $user = Auth::user();

        if (!$report->isVisibleTo($user)) {
            abort(403, 'Unauthorized');
        }

        // Only the creator or admin can update time entries
        if ($timeEntry->user_id !== $user->id && !$user->isAdmin()) {
            abort(403, 'Unauthorized');
        }

        if ($timeEntry->report_id !== $report->id) {
            abort(404);
        }

        $validated = $request->validate([
            'hours' => 'required|numeric|min:0.25|max:24',
            'description' => 'nullable|string|max:500',
            'logged_date' => 'required|date|before_or_equal:' . Report::businessToday(),
        ]);

        $oldValues = [
            'hours' => $timeEntry->hours,
            'description' => $timeEntry->description,
        ];

        $timeEntry->update($validated);

        // Log activity
        ReportActivityLog::create([
            'report_id' => $report->id,
            'user_id' => $user->id,
            'action' => 'updated_time',
            'old_values' => $oldValues,
            'new_values' => [
                'hours' => $validated['hours'],
                'description' => $validated['description'] ?? null,
            ],
        ]);

        $timeEntry->load('user:id,name,email');

        return response()->json([
            'message' => 'Time entry updated successfully.',
            'time_entry' => $timeEntry,
            'total_hours' => $report->getTotalTimeSpent(),
        ]);
    }

    public function destroy(Report $report, TimeEntry $timeEntry)
    {
        $user = Auth::user();

        if (!$report->isVisibleTo($user)) {
            abort(403, 'Unauthorized');
        }

        // Only the creator or admin can delete time entries
        if ($timeEntry->user_id !== $user->id && !$user->isAdmin()) {
            abort(403, 'Unauthorized');
        }

        if ($timeEntry->report_id !== $report->id) {
            abort(404);
        }

        $deletedHours = $timeEntry->hours;
        $timeEntry->delete();

        // Log activity
        ReportActivityLog::create([
            'report_id' => $report->id,
            'user_id' => $user->id,
            'action' => 'deleted_time',
            'old_values' => ['hours' => $deletedHours],
        ]);

        return response()->json([
            'message' => 'Time entry deleted successfully.',
            'total_hours' => $report->getTotalTimeSpent(),
        ]);
    }
}
