<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportActivityLog;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = Auth::user();

        // Only admin, super_admin, manager, and developer can access projects
        if (!in_array($user->role, ['super_admin', 'admin', 'manager', 'developer'])) {
            abort(403, 'Unauthorized');
        }

        $query = Project::with(['creator', 'users'])
            ->withCount([
                'reports',
                'reports as open_reports_count' => function ($query) {
                    $query->whereIn('status', ['open', 'reopened']);
                },
                'reports as in_progress_reports_count' => function ($query) {
                    $query->where('status', 'in_progress');
                },
                'reports as resolved_reports_count' => function ($query) {
                    $query->where('status', 'resolved');
                },
                'reports as closed_reports_count' => function ($query) {
                    $query->where('status', 'closed');
                }
            ]);

        // Developers can only see projects they're assigned to
        if ($user->isDeveloper()) {
            $query->whereHas('users', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            });
        }

        $projects = $query->orderBy('created_at', 'desc')->get();

        return Inertia::render('admin/projects/index', [
            'projects' => $projects,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $user = Auth::user();

        // Only admin, super_admin, and manager can create projects
        if (!$user->canManageProjects()) {
            abort(403, 'Unauthorized');
        }

        // Get clients and developers for assignment
        $clients = User::whereIn('role', ['client', 'developer'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        return Inertia::render('admin/projects/create', [
            'clients' => $clients,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        // Only admin, super_admin, and manager can create projects
        if (!$user->canManageProjects()) {
            abort(403, 'Unauthorized');
        }
        
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:projects,name',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive,completed',
            'client_ids' => 'required|array|min:1',
            'client_ids.*' => 'exists:users,id',
        ]);

        $project = Project::create([
            'name' => $validated['name'],
            'description' => $validated['description'],
            'status' => $validated['status'],
            'created_by' => Auth::id(),
        ]);

        $project->users()->attach($validated['client_ids'], [
            'role' => 'member',
            'assigned_by' => Auth::id(),
            'assigned_at' => now(),
        ]);

        app(NotificationService::class)->notifyProjectAssigned($project, $user, $validated['client_ids']);
        $this->logMembershipChange($project, $validated['client_ids'], []);

        return redirect()->route('projects.index')
            ->with('flash', [
                'message' => 'Project created successfully.',
                'type' => 'success'
            ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project)
    {
        $user = Auth::user();

        // Check access based on role
        if (!in_array($user->role, ['super_admin', 'admin', 'manager', 'developer'])) {
            abort(403, 'Unauthorized');
        }

        // Developers can only view projects they're assigned to
        if ($user->isDeveloper() && !$project->users->contains('id', $user->id)) {
            abort(403, 'Unauthorized');
        }

        $project->load(['creator', 'users', 'reports.reporter', 'reports.attachments']);

        return Inertia::render('admin/projects/show', [
            'project' => $project,
        ]);
    }

    /**
     * Display the specified resource by UUID.
     */
    public function showByUuid($uuid)
    {
        $user = Auth::user();

        // Check access based on role
        if (!in_array($user->role, ['super_admin', 'admin', 'manager', 'developer'])) {
            abort(403, 'Unauthorized');
        }

        $project = Project::where('uuid', $uuid)->firstOrFail();

        // Developers can only view projects they're assigned to
        if ($user->isDeveloper() && !$project->users->contains('id', $user->id)) {
            abort(403, 'Unauthorized');
        }

        $project->load(['creator', 'users', 'reports.reporter', 'reports.attachments']);

        return Inertia::render('admin/projects/show', [
            'project' => $project,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Project $project)
    {
        $user = Auth::user();

        // Only admin, super_admin, and manager can edit projects
        if (!$user->canManageProjects()) {
            abort(403, 'Unauthorized');
        }

        $project->load(['users']);
        // Get clients and developers for assignment
        $clients = User::whereIn('role', ['client', 'developer'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        return Inertia::render('admin/projects/edit', [
            'project' => $project,
            'clients' => $clients,
        ]);
    }

    /**
     * Show the form for editing the specified resource by UUID.
     */
    public function editByUuid($uuid)
    {
        $user = Auth::user();

        // Only admin, super_admin, and manager can edit projects
        if (!$user->canManageProjects()) {
            abort(403, 'Unauthorized');
        }

        $project = Project::where('uuid', $uuid)->firstOrFail();
        $project->load(['users']);
        // Get clients and developers for assignment
        $clients = User::whereIn('role', ['client', 'developer'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        return Inertia::render('admin/projects/edit', [
            'project' => $project,
            'clients' => $clients,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Project $project)
    {
        $user = Auth::user();

        // Only admin, super_admin, and manager can update projects
        if (!$user->canManageProjects()) {
            abort(403, 'Unauthorized');
        }
        
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:projects,name,' . $project->id,
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive,completed',
            'client_ids' => 'array',
            'client_ids.*' => 'exists:users,id',
        ]);

        // Track changes for notifications
        $changes = [];
        if ($project->name !== $validated['name']) {
            $changes['name'] = ['old' => $project->name, 'new' => $validated['name']];
        }
        if ($project->description !== $validated['description']) {
            $changes['description'] = ['old' => $project->description, 'new' => $validated['description']];
        }
        if ($project->status !== $validated['status']) {
            $changes['status'] = ['old' => $project->status, 'new' => $validated['status']];
        }

        $project->update([
            'name' => $validated['name'],
            'description' => $validated['description'],
            'status' => $validated['status'],
        ]);

        // If project is marked as completed, close all open reports
        $closedReportsCount = 0;
        if ($validated['status'] === 'completed' && isset($changes['status'])) {
            $closedReportsCount = $this->closeOpenReports($project, $user);
        }

        if (isset($validated['client_ids'])) {
            $synced = $project->users()->sync($validated['client_ids']);
            app(NotificationService::class)->notifyProjectAssigned($project, $user, $synced['attached']);
            $this->logMembershipChange($project, $synced['attached'], $synced['detached']);
        } else {
            $removedIds = $project->users()->pluck('users.id')->all();
            $project->users()->detach();
            $this->logMembershipChange($project, [], $removedIds);
        }

        // Send notifications if there were changes
        if (!empty($changes)) {
            app(NotificationService::class)->notifyProjectUpdated($project, $user, $changes);
        }

        $message = 'Project updated successfully.';
        if ($closedReportsCount > 0) {
            $message .= " {$closedReportsCount} open report(s) were automatically closed.";
        }

        return redirect()->route('projects.index')
            ->with('flash', [
                'message' => $message,
                'type' => 'success'
            ]);
    }

    /**
     * Update the specified resource in storage by UUID.
     */
    public function updateByUuid(Request $request, $uuid)
    {
        $user = Auth::user();

        // Only admin, super_admin, and manager can update projects
        if (!$user->canManageProjects()) {
            abort(403, 'Unauthorized');
        }
        
        $project = Project::where('uuid', $uuid)->firstOrFail();
        
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:projects,name,' . $project->id,
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive,completed',
            'client_ids' => 'array',
            'client_ids.*' => 'exists:users,id',
        ]);

        // Track changes for notifications
        $changes = [];
        if ($project->name !== $validated['name']) {
            $changes['name'] = ['old' => $project->name, 'new' => $validated['name']];
        }
        if ($project->description !== $validated['description']) {
            $changes['description'] = ['old' => $project->description, 'new' => $validated['description']];
        }
        if ($project->status !== $validated['status']) {
            $changes['status'] = ['old' => $project->status, 'new' => $validated['status']];
        }

        $project->update([
            'name' => $validated['name'],
            'description' => $validated['description'],
            'status' => $validated['status'],
        ]);

        // If project is marked as completed, close all open reports
        $closedReportsCount = 0;
        if ($validated['status'] === 'completed' && isset($changes['status'])) {
            $closedReportsCount = $this->closeOpenReports($project, $user);
        }

        if (isset($validated['client_ids'])) {
            $syncData = [];
            foreach ($validated['client_ids'] as $clientId) {
                $syncData[$clientId] = [
                    'role' => 'member',
                    'assigned_by' => Auth::id(),
                    'assigned_at' => now(),
                ];
            }
            $synced = $project->users()->sync($syncData);
            app(NotificationService::class)->notifyProjectAssigned($project, $user, $synced['attached']);
            $this->logMembershipChange($project, $synced['attached'], $synced['detached']);
        } else {
            $removedIds = $project->users()->pluck('users.id')->all();
            $project->users()->detach();
            $this->logMembershipChange($project, [], $removedIds);
        }

        // Send notifications if there were changes
        if (!empty($changes)) {
            app(NotificationService::class)->notifyProjectUpdated($project, $user, $changes);
        }

        $message = 'Project updated successfully.';
        if ($closedReportsCount > 0) {
            $message .= " {$closedReportsCount} open report(s) were automatically closed.";
        }

        return redirect()->route('projects.index')
            ->with('flash', [
                'message' => $message,
                'type' => 'success'
            ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project)
    {
        $user = Auth::user();

        // Only admin and super_admin can delete projects
        if (!$user->isAdmin()) {
            abort(403, 'Unauthorized');
        }

        $project->delete();

        return redirect()->route('projects.index')
            ->with('flash', [
                'message' => 'Project deleted successfully.',
                'type' => 'success'
            ]);
    }

    /**
     * Close all open reports when a project is marked as completed.
     * 
     * @param Project $project
     * @param User $user
     * @return int Number of reports that were closed
     */
    private function closeOpenReports(Project $project, User $user): int
    {
        // Get all open reports (open, reopened, in_progress)
        // but keep resolved and closed reports unchanged
        $openReports = Report::where('project_id', $project->id)
            ->whereIn('status', ['open', 'reopened', 'in_progress'])
            ->get();

        if ($openReports->isEmpty()) {
            return 0;
        }

        // Use database transaction to ensure atomicity
        DB::transaction(function () use ($openReports, $user, $project) {
            foreach ($openReports as $report) {
                $oldStatus = $report->status;
                
                // Update report status to closed
                $report->update(['status' => 'closed']);
                
                // Log the status change activity
                ReportActivityLog::create([
                    'report_id' => $report->id,
                    'user_id' => $user->id,
                    'action' => 'closed',
                    'comment' => "Automatically closed due to project '{$project->name}' being marked as completed.",
                    'old_values' => ['status' => $oldStatus],
                    'new_values' => ['status' => 'closed'],
                ]);

                // Send status change notifications
                $report->load('project', 'reporter');
                app(NotificationService::class)->notifyReportStatusChanged($report, $user, $oldStatus, 'closed');
            }
        });

        return $openReports->count();
    }

    /**
     * Record who was added to or removed from a project in the audit log.
     */
    private function logMembershipChange(Project $project, array $addedIds, array $removedIds): void
    {
        foreach (['Added' => $addedIds, 'Removed' => $removedIds] as $verb => $ids) {
            if (empty($ids)) {
                continue;
            }

            $names = User::whereIn('id', $ids)->orderBy('name')->pluck('name')->implode(', ');
            $preposition = $verb === 'Added' ? 'to' : 'from';

            AuditLog::record(
                'project.members_' . strtolower($verb),
                "{$verb} {$names} {$preposition} project \"{$project->name}\"",
                'project',
                $project->id,
                $project->name
            );
        }
    }
}