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
        $query = Report::with(['reporter:id,name,email', 'assignee:id,name,email', 'attachments', 'project'])
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

        if ($request->boolean('overdue')) {
            $query->whereNotNull('due_date')
                ->whereDate('due_date', '<', now()->toDateString())
                ->whereNotIn('status', Report::FINISHED_STATUSES);
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
                'reported_by' => Auth::id(),
                'status' => 'pending',
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
            'activityLogs.user:id,name,email,role',
            'comments.user:id,name,email,role'
        ]);

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
            case 'acknowledge':
                if (!$user->isAdmin() && $user->role !== 'super_admin') {
                    return response()->json(['message' => 'Only admins can acknowledge reports'], 403);
                }

                if ($report->status !== 'pending' && $report->status !== 'rejected') {
                    return response()->json(['message' => 'Report can only be acknowledged when pending or rejected'], 400);
                }

                $oldStatus = $report->status;
                $report->update(['status' => 'in_progress']);
                
                ReportActivityLog::create([
                    'report_id' => $report->id,
                    'user_id' => $user->id,
                    'action' => 'acknowledged',
                    'old_values' => ['status' => $oldStatus],
                    'new_values' => ['status' => 'in_progress'],
                ]);

                // Send notification for status change
                $report->load('project');
                app(NotificationService::class)->notifyReportStatusChanged($report, $user, $oldStatus, 'in_progress');
                break;

            case 'submit_for_review':
                if (!$user->isAdmin() && $user->role !== 'super_admin') {
                    return response()->json(['message' => 'Only admins can submit for review'], 403);
                }

                if ($report->status !== 'in_progress') {
                    return response()->json(['message' => 'Report must be in progress to submit for review'], 400);
                }

                $oldStatus = $report->status;
                $report->update(['status' => 'submit_for_review']);
                
                ReportActivityLog::create([
                    'report_id' => $report->id,
                    'user_id' => $user->id,
                    'action' => 'submitted_for_review',
                    'old_values' => ['status' => $oldStatus],
                    'new_values' => ['status' => 'submit_for_review'],
                ]);

                // Send notification for status change
                $report->load('project');
                app(NotificationService::class)->notifyReportStatusChanged($report, $user, $oldStatus, 'submit_for_review');
                break;

            case 'return_to_progress':
                if (!$user->isAdmin() && $user->role !== 'super_admin') {
                    return response()->json(['message' => 'Only admins can return reports to progress'], 403);
                }

                if ($report->status !== 'submit_for_review') {
                    return response()->json(['message' => 'Report must be submitted for review to return to progress'], 400);
                }

                $oldStatus = $report->status;
                $report->update(['status' => 'in_progress']);
                
                ReportActivityLog::create([
                    'report_id' => $report->id,
                    'user_id' => $user->id,
                    'action' => 'returned_to_progress',
                    'old_values' => ['status' => $oldStatus],
                    'new_values' => ['status' => 'in_progress'],
                ]);

                // Send notification for status change
                $report->load('project');
                app(NotificationService::class)->notifyReportStatusChanged($report, $user, $oldStatus, 'in_progress');
                break;

            case 'approve':
                if (!$user->isClient() || $report->reported_by !== $user->id) {
                    return response()->json(['message' => 'Only the reporting client can approve'], 403);
                }

                if ($report->status !== 'submit_for_review') {
                    return response()->json(['message' => 'Report must be submitted for review to approve'], 400);
                }

                $oldStatus = $report->status;
                $report->update([
                    'status' => 'approved',
                    'closed_at' => now()
                ]);
                
                ReportActivityLog::create([
                    'report_id' => $report->id,
                    'user_id' => $user->id,
                    'action' => 'approved',
                    'old_values' => ['status' => $oldStatus],
                    'new_values' => ['status' => 'approved'],
                ]);

                // Send notification for status change
                $report->load('project');
                app(NotificationService::class)->notifyReportStatusChanged($report, $user, $oldStatus, 'approved');
                break;

            case 'reject':
                if (!$user->isClient() || $report->reported_by !== $user->id) {
                    return response()->json(['message' => 'Only the reporting client can reject'], 403);
                }

                if ($report->status !== 'submit_for_review') {
                    return response()->json(['message' => 'Report must be submitted for review to reject'], 400);
                }

                $validated = $request->validate([
                    'comment' => 'required|string|min:5|max:2000'
                ]);

                $oldStatus = $report->status;
                $report->update(['status' => 'rejected']);
                
                ReportActivityLog::create([
                    'report_id' => $report->id,
                    'user_id' => $user->id,
                    'action' => 'rejected',
                    'old_values' => ['status' => $oldStatus],
                    'new_values' => ['status' => 'rejected', 'reason' => $validated['comment']],
                ]);

                // Keep the rejection reason in the comment thread
                ReportComment::create([
                    'report_id' => $report->id,
                    'user_id' => $user->id,
                    'comment' => $validated['comment'],
                ]);

                // Send notification for status change
                $report->load('project');
                app(NotificationService::class)->notifyReportStatusChanged($report, $user, $oldStatus, 'rejected', $validated['comment']);
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
                ];
                
                // Only admins and super_admins can update status
                if ($user->isAdmin() || $user->role === 'super_admin') {
                    $rules['status'] = ['required', Rule::in(['pending', 'in_progress', 'submit_for_review', 'approved', 'rejected', 'closed'])];
                }
                
                $validated = $request->validate($rules);

                // Capture old values for activity log
                $oldValues = [
                    'title' => $report->title,
                    'description' => $report->description,
                    'due_date' => $report->due_date,
                    'priority' => $report->priority,
                ];
                
                // Build update data
                $updateData = [
                    'title' => $validated['title'],
                    'description' => $validated['description'],
                    'due_date' => $validated['due_date'] ?? null,
                    'priority' => $validated['priority'],
                ];
                
                $newValues = [
                    'title' => $validated['title'],
                    'description' => $validated['description'],
                    'due_date' => $validated['due_date'] ?? null,
                    'priority' => $validated['priority'],
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
                    if ($oldValue !== $newValue) {
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
                    'old_values' => ['status' => $oldStatus],
                    'new_values' => ['status' => 'closed'],
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

                // Send notification for new comment
                $report->load('project', 'reporter');
                app(NotificationService::class)->notifyCommentAdded($report, $comment, $user);
                break;

            default:
                return response()->json(['message' => 'Invalid action'], 400);
        }

        return response()->json([
            'message' => 'Report updated successfully',
            'report' => $report->fresh()->load([
                'reporter:id,name,email,role',
                'assignee:id,name,email,role',
                'attachments',
                'activityLogs.user:id,name,email,role',
                'comments.user:id,name,email,role'
            ])
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

        app(NotificationService::class)->notifyReportDeleted($report, $user);

        $report->delete();

        return response()->json(['message' => 'Report deleted successfully']);
    }
}
