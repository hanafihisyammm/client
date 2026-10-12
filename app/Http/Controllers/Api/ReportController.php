<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\ReportAttachment;
use App\Models\ReportComment;
use App\Models\ReportActivityLog;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $query = Report::with(['reporter:id,name,email', 'assignee:id,name,email', 'attachments', 'project', 'labels'])
            ->orderBy('created_at', 'desc');

        // Clients and developers only see reports they raised, are assigned to, or that are in their projects
        $query->visibleTo($user);

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('project') && $request->project !== 'all') {
            $query->where('project_id', $request->project);
        }

        if ($request->has('assigned_to') && $request->assigned_to !== 'all') {
            $query->where('assigned_to', $request->assigned_to);
        }

        if ($request->has('severity') && $request->severity !== 'all') {
            // Features may still hold the severity they had as a bug, so only bugs count
            $query->where('type', Report::TYPE_BUG)->where('severity', $request->severity);
        }

        if ($request->has('priority') && $request->priority !== 'all') {
            $query->where('priority', $request->priority);
        }

        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $reports = $query->paginate($request->get('per_page', 15));

        return response()->json($reports);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'project_id' => 'required|exists:projects,id',
            // "Today" is the Malaysian calendar day, not the UTC one
            'due_date' => 'nullable|date|after_or_equal:' . Report::businessToday(),
            'priority' => 'required|in:low,medium,high,urgent',
            'type' => 'nullable|in:' . implode(',', Report::TYPES),
            'severity' => 'nullable|in:critical,major,minor,trivial',
            'estimated_hours' => 'nullable|numeric|min:0',
            'label_ids' => 'nullable|array',
            'label_ids.*' => 'exists:labels,id',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:jpeg,jpg,png,gif,pdf|max:5120',
        ]);

        $user = Auth::user();
        
        // Get project name for the legacy project column
        $project = \App\Models\Project::find($validated['project_id']);
        if (!$project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        // Managers can only use their company's projects, staff only the projects they are assigned to
        if (!$project->isVisibleTo($user)) {
            return response()->json(['message' => 'You do not have access to this project.'], 403);
        }

        // New issues can only be raised against a project that is still active
        if (!$project->isActive()) {
            return response()->json([
                'message' => 'Issues can only be created in an active project.',
                'errors' => ['project_id' => ['This project is ' . $project->status . '. Choose an active project.']],
            ], 422);
        }

        $type = $validated['type'] ?? Report::TYPE_BUG;

        DB::beginTransaction();
        try {
            $report = Report::create([
                'title' => $validated['title'],
                'description' => $validated['description'],
                'project' => $project->name,
                'project_id' => $validated['project_id'],
                'due_date' => $validated['due_date'] ?? null,
                'priority' => $validated['priority'],
                'type' => $type,
                // Severity describes how badly something is broken, so only bugs have one
                'severity' => $type === Report::TYPE_BUG ? ($validated['severity'] ?? 'minor') : null,
                'estimated_hours' => $validated['estimated_hours'] ?? null,
                'reported_by' => Auth::id(),
                'status' => Report::STATUS_OPEN,
            ]);

            // Attach labels if provided
            if (!empty($validated['label_ids'])) {
                $report->labels()->attach($validated['label_ids']);
            }

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $filename = Str::random(40) . '.' . $file->getClientOriginalExtension();
                    $storagePath = $file->storeAs('reports/' . $report->id, $filename, 'public');

                    ReportAttachment::create([
                        'report_id' => $report->id,
                        'filename' => $filename,
                        'original_filename' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'storage_path' => $storagePath,
                    ]);
                }
            }

            DB::commit();

            // Send notifications for new report
            $report->load('project');
            app(NotificationService::class)->notifyReportCreated($report, $user);

            return response()->json([
                'message' => 'Issue created successfully.',
                'report' => $report->load(['reporter:id,name,email', 'attachments'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create report'], 500);
        }
    }

    public function show(Report $report): JsonResponse
    {
        $user = Auth::user();
        
        if (!$report->isVisibleTo($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $report->load([
            'reporter:id,name,email,role',
            'assignee:id,name,email,role',
            'attachments',
            'activityLogs.user:id,name,email,role',
            'comments.user:id,name,email,role',
            'labels',
            'timeEntries.user:id,name,email',
            'outgoingLinks.targetReport:id,uuid,title,status,priority',
            'outgoingLinks.creator:id,name',
            'incomingLinks.sourceReport:id,uuid,title,status,priority',
            'incomingLinks.creator:id,name',
        ]);

        // Add computed values
        $report->total_time_spent = $report->getTotalTimeSpent();

        return response()->json(['report' => $report, 'abilities' => $report->abilitiesFor($user)]);
    }

    public function update(Request $request, Report $report): JsonResponse
    {
        $user = Auth::user();

        $action = $request->input('action');
        $message = 'Issue updated successfully.';

        $abilities = $report->abilitiesFor($user);
        if (!$abilities['view']) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Status changes: first check the move exists in the workflow, then that this user may make it
        $targetStatus = match ($action) {
            'start_progress' => Report::STATUS_IN_PROGRESS,
            'resolve' => Report::STATUS_RESOLVED,
            'close', 'close_report' => Report::STATUS_CLOSED,
            'reopen' => Report::STATUS_REOPENED,
            'transition' => $request->input('new_status'),
            default => null,
        };

        if (is_string($targetStatus) && in_array($targetStatus, Report::STATUSES, true)) {
            if (!$report->canTransitionTo($targetStatus)) {
                return response()->json([
                    'message' => 'An issue cannot move from ' . $report->getStatusLabel() . ' to ' . Report::STATUS_LABELS[$targetStatus] . '.',
                    'valid_transitions' => $report->getValidTransitions(),
                ], 400);
            }

            $needed = $report->abilityNeededFor($targetStatus);
        } else {
            $needed = match ($action) {
                'update_report' => 'edit',
                'assign' => 'assign',
                'add_comment', 'add_attachments' => 'comment',
                default => null,
            };
        }

        if ($needed !== null && !$abilities[$needed]) {
            $resolvedItThemselves = $needed === 'confirm' && $report->resolved_by === $user->id;

            return response()->json([
                'message' => $resolvedItThemselves
                    ? 'You resolved this issue, so someone else has to confirm the fix.'
                    : 'You are not allowed to do that.',
            ], 403);
        }

        if (!$report->isVisibleTo($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Clients can only act on reports they created, except that any client on the
        // project may review a resolved report (confirm the fix or reopen it)
        switch ($action) {
            // Start progress on an issue (open/reopened -> in_progress)
            case 'start_progress':
                if (!$report->canTransitionTo(Report::STATUS_IN_PROGRESS)) {
                    return response()->json(['message' => 'Cannot start progress from current status'], 400);
                }

                $report->startProgress($user);

                // Auto-assign if not assigned
                if (!$report->assigned_to) {
                    $report->update(['assigned_to' => $user->id]);
                }

                $message = 'Issue moved to In Progress.';
                break;

            // Resolve an issue (in_progress -> resolved)
            case 'resolve':
                if (!$report->canTransitionTo(Report::STATUS_RESOLVED)) {
                    return response()->json(['message' => 'Cannot resolve from current status'], 400);
                }

                $validated = $request->validate([
                    'resolution_summary' => 'nullable|string|max:2000'
                ]);

                $report->resolve($user, $validated['resolution_summary'] ?? 'Issue resolved');

                $report->recordResolution();

                $message = 'Issue marked as resolved.';
                break;

            // Close an issue (resolved -> closed) - Client confirms fix works
            case 'close':
                if (!$report->canTransitionTo(Report::STATUS_CLOSED)) {
                    return response()->json(['message' => 'Cannot close from current status'], 400);
                }

                $report->close($user);

                $message = 'Issue closed successfully.';
                break;

            // Reopen an issue (resolved/closed -> reopened) - Client says fix didn't work
            case 'reopen':
                if (!$report->canTransitionTo(Report::STATUS_REOPENED)) {
                    return response()->json(['message' => 'Cannot reopen from current status'], 400);
                }

                $validated = $request->validate([
                    'comment' => 'nullable|string|max:2000'
                ]);

                $report->reopen($user, $validated['comment'] ?? null);

                // Add comment explaining why reopened
                if (!empty($validated['comment'])) {
                    ReportComment::create([
                        'report_id' => $report->id,
                        'user_id' => $user->id,
                        'comment' => $validated['comment'],
                    ]);
                }

                $message = 'Issue reopened successfully.';
                break;

            // Generic status transition
            case 'transition':
                $validated = $request->validate([
                    'new_status' => ['required', Rule::in(Report::STATUSES)],
                    'resolution_summary' => 'nullable|string|max:2000'
                ]);

                if (!$report->canTransitionTo($validated['new_status'])) {
                    return response()->json([
                        'message' => 'An issue cannot move from ' . $report->getStatusLabel() . ' to ' . (Report::STATUS_LABELS[$validated['new_status']] ?? $validated['new_status']) . '.',
                        'valid_transitions' => $report->getValidTransitions()
                    ], 400);
                }

                $report->transitionTo($validated['new_status'], $user, $validated['resolution_summary'] ?? null);

                // Same follow-up as the dedicated "start progress" and "resolve" actions
                if ($validated['new_status'] === Report::STATUS_IN_PROGRESS) {
                    if (!$report->assigned_to) {
                        $report->update(['assigned_to' => $user->id]);
                    }
                } elseif ($validated['new_status'] === Report::STATUS_RESOLVED) {
                    $report->recordResolution();
                }

                $message = match ($validated['new_status']) {
                    Report::STATUS_RESOLVED => 'Issue marked as resolved.',
                    Report::STATUS_CLOSED => 'Issue closed successfully.',
                    Report::STATUS_REOPENED => 'Issue reopened successfully.',
                    Report::STATUS_IN_PROGRESS => 'Issue moved to In Progress.',
                    default => 'Issue moved back to Open.',
                };
                break;

            case 'update_report':
                // Different validation rules for clients vs admins
                $rules = [
                    'title' => 'required|string|max:255',
                    'description' => 'required|string',
                    'due_date' => 'nullable|date',
                    'priority' => 'required|in:low,medium,high,urgent',
                    'type' => 'nullable|in:' . implode(',', Report::TYPES),
                    'severity' => 'nullable|in:critical,major,minor,trivial',
                    'estimated_hours' => 'nullable|numeric|min:0',
                    'label_ids' => 'nullable|array',
                    'label_ids.*' => 'exists:labels,id',
                ];

                // Only admins and super_admins can update status
                if ($user->isAdmin() || $user->role === 'super_admin') {
                    $rules['status'] = ['required', Rule::in(Report::STATUSES)];
                }

                $validated = $request->validate($rules);

                // The due date can be cleared or left as it is, even if it has already passed,
                // but a new date has to be today or later (in Malaysia), as when creating an issue
                $newDueDate = !empty($validated['due_date']) ? \Illuminate\Support\Carbon::parse($validated['due_date'])->toDateString() : null;
                if ($newDueDate !== null && $newDueDate !== $report->due_date?->toDateString() && $newDueDate < Report::businessToday()) {
                    return response()->json([
                        'message' => 'The due date must be today or later.',
                        'errors' => ['due_date' => ['The due date must be today or later.']],
                    ], 422);
                }

                // Capture old values for activity log
                $oldValues = [
                    'title' => $report->title,
                    'description' => $report->description,
                    'due_date' => $report->due_date,
                    'priority' => $report->priority,
                    'type' => $report->type,
                    'severity' => $report->severity,
                    'estimated_hours' => $report->estimated_hours,
                ];

                $type = $validated['type'] ?? $report->type;

                // A feature has no severity, whatever the form sends. A bug uses the one chosen,
                // else the one it already has (including from before it became a feature), else Minor.
                $severity = $type === Report::TYPE_BUG
                    ? ($validated['severity'] ?? $report->storedSeverity() ?? 'minor')
                    : null;

                // Build update data
                $updateData = [
                    'title' => $validated['title'],
                    'description' => $validated['description'],
                    'due_date' => $newDueDate,
                    'priority' => $validated['priority'],
                    'type' => $type,
                    'estimated_hours' => $validated['estimated_hours'] ?? $report->estimated_hours,
                ];

                // Turning a bug into a feature leaves its severity stored, so it can come back
                if ($type === Report::TYPE_BUG) {
                    $updateData['severity'] = $severity;
                }

                $newValues = [
                    'title' => $validated['title'],
                    'description' => $validated['description'],
                    'due_date' => $newDueDate,
                    'priority' => $validated['priority'],
                    'type' => $type,
                    'severity' => $severity,
                    'estimated_hours' => $validated['estimated_hours'] ?? $report->estimated_hours,
                ];

                // Check for status change for notifications
                $statusChanged = false;
                $oldStatus = $report->status;
                $newStatus = $oldStatus;

                // Only update status if provided (admins/super_admins only)
                if (isset($validated['status'])) {
                    $oldValues['status'] = $report->status;
                    $updateData['status'] = $validated['status'];
                    $newValues['status'] = $validated['status'];
                    $statusChanged = ($oldStatus !== $validated['status']);
                    $newStatus = $validated['status'];
                }

                $report->update($updateData);

                // Update labels if provided
                if (isset($validated['label_ids'])) {
                    $report->labels()->sync($validated['label_ids']);
                }

                // Log the update activity with changes
                ReportActivityLog::create([
                    'report_id' => $report->id,
                    'user_id' => $user->id,
                    'action' => 'updated',
                    'old_values' => $oldValues,
                    'new_values' => $newValues,
                ]);

                // Work out which details actually changed
                $changes = [];
                foreach ($newValues as $field => $newValue) {
                    $oldValue = $oldValues[$field];
                    if ($field === 'due_date') {
                        $oldValue = $oldValue?->toDateString();
                        $newValue = $newValue ? \Illuminate\Support\Carbon::parse($newValue)->toDateString() : null;
                    }
                    // Hours come back from the database as "2.50" but arrive from the form as 2.5
                    $same = is_numeric($oldValue) && is_numeric($newValue)
                        ? (float) $oldValue === (float) $newValue
                        : $oldValue === $newValue;
                    if (!$same) {
                        $changes[$field] = ['old' => $oldValue, 'new' => $newValue];
                    }
                }

                // Send one notification: a status change on its own, otherwise the list of edits
                $report->load('project');
                if ($statusChanged && count($changes) === 1) {
                    app(NotificationService::class)->notifyReportStatusChanged($report, $user, $oldStatus, $newStatus);
                } elseif (!empty($changes)) {
                    app(NotificationService::class)->notifyReportUpdated($report, $user, $changes);
                }

                $message = 'Issue updated successfully.';
                break;

            case 'close_report':
                // Reports can be closed from most statuses
                if ($report->status === 'closed') {
                    return response()->json(['message' => 'Report is already closed'], 400);
                }

                $oldStatus = $report->status;
                $report->update([
                    'status' => 'closed',
                    'closed_at' => now()
                ]);
                
                ReportActivityLog::create([
                    'report_id' => $report->id,
                    'user_id' => $user->id,
                    'action' => 'closed',
                    'old_values' => ['status' => $oldStatus],
                    'new_values' => ['status' => 'closed'],
                ]);

                // Send notification for status change
                $report->load('project');
                app(NotificationService::class)->notifyReportStatusChanged($report, $user, $oldStatus, 'closed');

                $message = 'Issue closed successfully.';
                break;

            case 'add_comment':
                $validated = $request->validate([
                    'comment' => 'required|string|min:1|max:2000',
                ]);

                $comment = ReportComment::create([
                    'report_id' => $report->id,
                    'user_id' => $user->id,
                    'comment' => $validated['comment'],
                ]);

                // Send notification for new comment
                $report->load('project', 'reporter');
                app(NotificationService::class)->notifyCommentAdded($report, $comment, $user);

                $message = 'Comment added successfully.';
                break;

            case 'assign':
                $validated = $request->validate([
                    'assignee_id' => 'nullable|exists:users,id',
                ]);

                $oldAssignee = $report->assignee;
                $newAssigneeId = $validated['assignee_id'];

                // The assignee must be able to open the issue: an admin, the company's manager, or staff on its project
                $candidate = $newAssigneeId ? \App\Models\User::find($newAssigneeId) : null;
                if ($candidate && !$report->canBeAssignedTo($candidate)) {
                    return response()->json([
                        'message' => $candidate->isStaff() && $candidate->isActive()
                            ? "{$candidate->name} is not on this project. Add them to the project first."
                            : 'Issues can only be assigned to active people who work on this project.',
                    ], 422);
                }

                $report->update(['assigned_to' => $newAssigneeId]);

                // Log the assignment
                $newAssignee = $newAssigneeId ? \App\Models\User::find($newAssigneeId) : null;
                ReportActivityLog::create([
                    'report_id' => $report->id,
                    'user_id' => $user->id,
                    'action' => $newAssigneeId ? 'assigned' : 'unassigned',
                    'old_values' => ['assigned_to' => $oldAssignee?->id, 'assignee_name' => $oldAssignee?->name],
                    'new_values' => ['assigned_to' => $newAssigneeId, 'assignee_name' => $newAssignee?->name],
                ]);

                // Send notification to the new assignee
                if ($newAssignee && $newAssignee->id !== $user->id) {
                    $report->load('project');
                    app(NotificationService::class)->notifyReportAssigned($report, $user, $newAssignee);
                }

                $message = $newAssignee ? "Issue assigned to {$newAssignee->name}." : 'Issue unassigned.';
                break;

            case 'add_attachments':
                $request->validate([
                    'attachments' => 'required|array|min:1|max:5',
                    'attachments.*' => 'file|mimes:jpeg,jpg,png,gif,pdf,webp|max:5120',
                ]);

                if ($request->hasFile('attachments')) {
                    foreach ($request->file('attachments') as $file) {
                        $filename = Str::random(40) . '.' . $file->getClientOriginalExtension();
                        $storagePath = $file->storeAs('reports/' . $report->id, $filename, 'public');

                        ReportAttachment::create([
                            'report_id' => $report->id,
                            'filename' => $filename,
                            'original_filename' => $file->getClientOriginalName(),
                            'mime_type' => $file->getMimeType(),
                            'file_size' => $file->getSize(),
                            'storage_path' => $storagePath,
                        ]);
                    }

                    ReportActivityLog::create([
                        'report_id' => $report->id,
                        'user_id' => $user->id,
                        'action' => 'added_attachments',
                    ]);
                }

                $message = 'Attachments uploaded successfully.';
                break;

            default:
                return response()->json(['message' => 'Invalid action'], 400);
        }

        $freshReport = $report->fresh()->load([
            'reporter:id,name,email,role',
            'assignee:id,name,email,role',
            'attachments',
            'activityLogs.user:id,name,email,role',
            'comments.user:id,name,email,role',
            'labels',
            'timeEntries.user:id,name,email',
            'outgoingLinks.targetReport:id,uuid,title,status,priority',
            'outgoingLinks.creator:id,name',
            'incomingLinks.sourceReport:id,uuid,title,status,priority',
            'incomingLinks.creator:id,name',
        ]);
        $freshReport->total_time_spent = $freshReport->getTotalTimeSpent();

        return response()->json([
            'message' => $message,
            'report' => $freshReport,
            // What this user may do now that the status has changed
            'abilities' => $freshReport->abilitiesFor($user),
        ]);
    }

    public function destroy(Report $report): JsonResponse
    {
        $user = Auth::user();

        if (!$report->abilitiesFor($user)['delete']) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        foreach ($report->attachments as $attachment) {
            Storage::disk('public')->delete($attachment->storage_path);
        }

        app(NotificationService::class)->notifyReportDeleted($report, $user);

        $report->delete();

        return response()->json(['message' => 'Issue deleted successfully.']);
    }
}
