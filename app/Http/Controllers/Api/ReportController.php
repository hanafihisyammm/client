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

        // Filter reports based on user role and project membership
        if ($user->isClient()) {
            // Clients can only see reports from projects they're assigned to
            $userProjectIds = \DB::table('project_users')
                ->where('user_id', $user->id)
                ->pluck('project_id');
            $query->where(function($q) use ($user, $userProjectIds) {
                // Reports they created OR reports from their projects
                $q->where('reported_by', $user->id)
                  ->orWhereIn('project_id', $userProjectIds);
            });
        }

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('project') && $request->project !== 'all') {
            if ($user->isClient()) {
                // Ensure client can only filter by their accessible projects
                $userProjectIds = \DB::table('project_users')
                    ->where('user_id', $user->id)
                    ->pluck('project_id');
                if (in_array($request->project, $userProjectIds->toArray())) {
                    $query->where('project_id', $request->project);
                }
            } else {
                $query->where('project_id', $request->project);
            }
        }

        if ($request->has('assigned_to') && $request->assigned_to !== 'all') {
            $query->where('assigned_to', $request->assigned_to);
        }

        if ($request->has('severity') && $request->severity !== 'all') {
            $query->where('severity', $request->severity);
        }

        if ($request->has('priority') && $request->priority !== 'all') {
            $query->where('priority', $request->priority);
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
            'due_date' => 'nullable|date|after_or_equal:today',
            'priority' => 'required|in:low,medium,high,urgent',
            'severity' => 'nullable|in:critical,major,minor,trivial',
            'estimated_hours' => 'nullable|numeric|min:0',
            'label_ids' => 'nullable|array',
            'label_ids.*' => 'exists:labels,id',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:jpeg,jpg,png,gif,pdf|max:5120',
        ]);

        $user = Auth::user();
        
        // If client, validate they have access to the selected project
        if ($user->isClient()) {
            $userProjectIds = \DB::table('project_users')
                ->where('user_id', $user->id)
                ->pluck('project_id');
            if ($userProjectIds->isEmpty()) {
                return response()->json(['message' => 'You must be assigned to a project before creating reports'], 403);
            }
            if (!$userProjectIds->contains($validated['project_id'])) {
                return response()->json(['message' => 'Unauthorized access to selected project'], 403);
            }
        }


        // Get project name for the legacy project column
        $project = \App\Models\Project::find($validated['project_id']);
        if (!$project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        DB::beginTransaction();
        try {
            $report = Report::create([
                'title' => $validated['title'],
                'description' => $validated['description'],
                'project' => $project->name,
                'project_id' => $validated['project_id'],
                'due_date' => $validated['due_date'] ?? null,
                'priority' => $validated['priority'],
                'severity' => $validated['severity'] ?? 'minor',
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
                'message' => 'Report created successfully',
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
        
        if ($user->isClient()) {
            // Check if client has access to this report
            $hasAccess = false;
            
            // They created the report
            if ($report->reported_by === $user->id) {
                $hasAccess = true;
            }
            
            // Or the report belongs to one of their projects
            if ($report->project_id) {
                $userProjectIds = \DB::table('project_users')
                    ->where('user_id', $user->id)
                    ->pluck('project_id');
                if ($userProjectIds->contains($report->project_id)) {
                    $hasAccess = true;
                }
            }
            
            if (!$hasAccess) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        $report->load([
            'reporter:id,name,email,role',
            'assignee:id,name,email,role',
            'attachments',
            'activityLogs.user:id,name,email',
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
        $report->sla = $report->getSlaInfo();

        return response()->json(['report' => $report]);
    }

    public function update(Request $request, Report $report): JsonResponse
    {
        $user = Auth::user();

        if ($user->isClient() && $report->reported_by !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $action = $request->input('action');

        switch ($action) {
            // Start progress on an issue (open/reopened -> in_progress)
            case 'start_progress':
                if (!$user->isAdmin() && $user->role !== 'super_admin' && !in_array($user->role, ['manager', 'developer'])) {
                    return response()->json(['message' => 'Only team members can start progress on issues'], 403);
                }

                if (!$report->canTransitionTo(Report::STATUS_IN_PROGRESS)) {
                    return response()->json(['message' => 'Cannot start progress from current status'], 400);
                }

                $report->startProgress($user);

                // Auto-assign if not assigned
                if (!$report->assigned_to) {
                    $report->update(['assigned_to' => $user->id]);
                }

                // Record first response for SLA
                $report->recordFirstResponse();
                break;

            // Resolve an issue (in_progress -> resolved)
            case 'resolve':
                if (!$user->isAdmin() && $user->role !== 'super_admin' && !in_array($user->role, ['manager', 'developer'])) {
                    return response()->json(['message' => 'Only team members can resolve issues'], 403);
                }

                if (!$report->canTransitionTo(Report::STATUS_RESOLVED)) {
                    return response()->json(['message' => 'Cannot resolve from current status'], 400);
                }

                $validated = $request->validate([
                    'resolution_summary' => 'nullable|string|max:2000'
                ]);

                $report->resolve($user, $validated['resolution_summary'] ?? 'Issue resolved');

                // Record resolution time for SLA
                $report->recordResolution();
                break;

            // Close an issue (resolved -> closed) - Client confirms fix works
            case 'close':
                // Allow clients who own the report, or admins to close
                if (!$user->isAdmin() && $user->role !== 'super_admin' &&
                    !($user->isClient() && $report->reported_by === $user->id)) {
                    return response()->json(['message' => 'Not authorized to close this issue'], 403);
                }

                if (!$report->canTransitionTo(Report::STATUS_CLOSED)) {
                    return response()->json(['message' => 'Cannot close from current status'], 400);
                }

                $report->close($user);
                break;

            // Reopen an issue (resolved/closed -> reopened) - Client says fix didn't work
            case 'reopen':
                // Allow clients who own the report, or admins to reopen
                if (!$user->isAdmin() && $user->role !== 'super_admin' &&
                    !($user->isClient() && $report->reported_by === $user->id)) {
                    return response()->json(['message' => 'Not authorized to reopen this issue'], 403);
                }

                if (!$report->canTransitionTo(Report::STATUS_REOPENED)) {
                    return response()->json(['message' => 'Cannot reopen from current status'], 400);
                }

                $validated = $request->validate([
                    'comment' => 'nullable|string|max:2000'
                ]);

                $report->reopen($user);

                // Add comment explaining why reopened
                if (!empty($validated['comment'])) {
                    ReportComment::create([
                        'report_id' => $report->id,
                        'user_id' => $user->id,
                        'comment' => $validated['comment'],
                    ]);
                }
                break;

            // Generic status transition
            case 'transition':
                $validated = $request->validate([
                    'new_status' => ['required', Rule::in(Report::STATUSES)],
                    'resolution_summary' => 'nullable|string|max:2000'
                ]);

                if (!$report->canTransitionTo($validated['new_status'])) {
                    return response()->json([
                        'message' => 'Invalid status transition',
                        'valid_transitions' => $report->getValidTransitions()
                    ], 400);
                }

                $report->transitionTo($validated['new_status'], $user, $validated['resolution_summary'] ?? null);
                break;

            case 'update_report':
                // Allow admins, super_admins, and clients who own the report to edit
                if (!$user->isAdmin() && $user->role !== 'super_admin' && 
                    !($user->isClient() && $report->reported_by === $user->id)) {
                    return response()->json(['message' => 'Not authorized to edit this report'], 403);
                }

                // Different validation rules for clients vs admins
                $rules = [
                    'title' => 'required|string|max:255',
                    'description' => 'required|string',
                    'due_date' => 'nullable|date',
                    'priority' => 'required|in:low,medium,high,urgent',
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

                // Capture old values for activity log
                $oldValues = [
                    'title' => $report->title,
                    'description' => $report->description,
                    'due_date' => $report->due_date,
                    'priority' => $report->priority,
                    'severity' => $report->severity,
                    'estimated_hours' => $report->estimated_hours,
                ];

                // Build update data
                $updateData = [
                    'title' => $validated['title'],
                    'description' => $validated['description'],
                    'due_date' => $validated['due_date'] ?? null,
                    'priority' => $validated['priority'],
                    'severity' => $validated['severity'] ?? $report->severity,
                    'estimated_hours' => $validated['estimated_hours'] ?? $report->estimated_hours,
                ];

                $newValues = [
                    'title' => $validated['title'],
                    'description' => $validated['description'],
                    'due_date' => $validated['due_date'] ?? null,
                    'priority' => $validated['priority'],
                    'severity' => $validated['severity'] ?? $report->severity,
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

                // Send notification if status changed
                if ($statusChanged) {
                    $report->load('project');
                    app(NotificationService::class)->notifyReportStatusChanged($report, $user, $oldStatus, $newStatus);
                }
                break;

            case 'close_report':
                // Allow clients to close their own reports, admins and super_admins can close any report
                if (!$user->isAdmin() && $user->role !== 'super_admin' && 
                    !($user->isClient() && $report->reported_by === $user->id)) {
                    return response()->json(['message' => 'Not authorized to close this report'], 403);
                }

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
                ]);

                // Send notification for status change
                $report->load('project');
                app(NotificationService::class)->notifyReportStatusChanged($report, $user, $oldStatus, 'closed');
                break;

            case 'add_comment':
                // Allow admins, super_admins, and clients (if they own the report) to comment
                if (!$user->isAdmin() && $user->role !== 'super_admin' &&
                    !($user->isClient() && $report->reported_by === $user->id)) {
                    return response()->json(['message' => 'Not authorized to comment'], 403);
                }

                $validated = $request->validate([
                    'comment' => 'required|string|min:1|max:2000',
                ]);

                $comment = ReportComment::create([
                    'report_id' => $report->id,
                    'user_id' => $user->id,
                    'comment' => $validated['comment'],
                ]);

                // Record first response for SLA (if team member commenting, not the reporter)
                if (!$user->isClient() && $report->reported_by !== $user->id) {
                    $report->recordFirstResponse();
                }

                // Send notification for new comment
                $report->load('project', 'reporter');
                app(NotificationService::class)->notifyCommentAdded($report, $comment, $user);
                break;

            case 'assign':
                // Only admins and managers can assign issues
                if (!$user->isAdmin() && $user->role !== 'super_admin' && $user->role !== 'manager') {
                    return response()->json(['message' => 'Only admins and managers can assign issues'], 403);
                }

                $validated = $request->validate([
                    'assignee_id' => 'nullable|exists:users,id',
                ]);

                $oldAssignee = $report->assignee;
                $newAssigneeId = $validated['assignee_id'];

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
                break;

            case 'add_attachments':
                // Allow admins, super_admins, managers, developers, and clients who own the report
                if (!$user->isAdmin() && $user->role !== 'super_admin' &&
                    !in_array($user->role, ['manager', 'developer']) &&
                    !($user->isClient() && $report->reported_by === $user->id)) {
                    return response()->json(['message' => 'Not authorized to add attachments'], 403);
                }

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
                break;

            default:
                return response()->json(['message' => 'Invalid action'], 400);
        }

        $freshReport = $report->fresh()->load([
            'reporter:id,name,email,role',
            'assignee:id,name,email,role',
            'attachments',
            'activityLogs.user:id,name,email',
            'comments.user:id,name,email,role',
            'labels',
            'timeEntries.user:id,name,email',
            'outgoingLinks.targetReport:id,uuid,title,status,priority',
            'outgoingLinks.creator:id,name',
            'incomingLinks.sourceReport:id,uuid,title,status,priority',
            'incomingLinks.creator:id,name',
        ]);
        $freshReport->total_time_spent = $freshReport->getTotalTimeSpent();
        $freshReport->sla = $freshReport->getSlaInfo();

        return response()->json([
            'message' => 'Report updated successfully',
            'report' => $freshReport
        ]);
    }

    public function destroy(Report $report): JsonResponse
    {
        $user = Auth::user();

        if (!$user->isAdmin() && ($user->isClient() && $report->reported_by !== $user->id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        foreach ($report->attachments as $attachment) {
            Storage::disk('public')->delete($attachment->storage_path);
        }

        $report->delete();

        return response()->json(['message' => 'Report deleted successfully']);
    }
}
