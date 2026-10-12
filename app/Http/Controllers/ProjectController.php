<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportActivityLog;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = Auth::user();

        // Admins see every project, a manager their company's, staff the ones they are assigned to
        $projects = Project::visibleTo($user)
            // The company's manager is on the team of every project in the company
            ->with(['creator', 'users', 'company:id,uuid,name,manager_id', 'company.manager:id,name,email,role,deactivated_at'])
            ->withCount([
                'reports',
                'reports as open_reports_count' => function ($query) {
                    $query->where('status', 'open');
                },
                'reports as reopened_reports_count' => function ($query) {
                    $query->where('status', 'reopened');
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
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('admin/projects/index', [
            'projects' => $projects,
            // Only admins and managers may create, edit or delete projects
            'canManage' => $user->canManageProjects() && ($user->isAdmin() || $user->company_id !== null),
            // Admins work across companies, so their list shows which company each project belongs to
            'showCompany' => $user->isAdmin(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $user = Auth::user();
        $this->ensureCanCreateProjects($user);

        return Inertia::render('admin/projects/create', [
            'clients' => $this->assignableStaff($user),
            'companies' => $this->selectableCompanies($user),
            // A manager's projects always belong to their own company
            'fixedCompanyId' => $user->isAdmin() ? null : $user->company_id,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        $this->ensureCanCreateProjects($user);

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:projects,name',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive,completed',
            'company_id' => $user->isAdmin() ? 'required|exists:companies,id' : 'nullable',
            'client_ids' => 'array',
            'client_ids.*' => 'exists:users,id',
        ], [
            'company_id.required' => 'Please choose the client company this project belongs to.',
        ]);

        // A manager can never create a project for another company, whatever the request says
        $companyId = $user->isAdmin() ? (int) $validated['company_id'] : $user->company_id;
        $memberIds = $this->validatedMemberIds($validated['client_ids'] ?? [], $companyId);

        $project = Project::create([
            'company_id' => $companyId,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
            'created_by' => Auth::id(),
        ]);

        $project->users()->attach($memberIds, [
            'role' => 'member',
            'assigned_by' => Auth::id(),
            'assigned_at' => now(),
        ]);

        app(NotificationService::class)->notifyProjectCreated($project, $user);
        app(NotificationService::class)->notifyProjectAssigned($project, $user, $memberIds);
        $memberMessage = $this->logMembershipChange($project, $memberIds, []);

        return redirect()->route('projects.index')
            ->with('flash', [
                'message' => 'Project created successfully.' . $memberMessage,
                'type' => 'success'
            ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project)
    {
        return $this->renderProject($project);
    }

    /**
     * Display the specified resource by UUID.
     */
    public function showByUuid($uuid)
    {
        return $this->renderProject(Project::where('uuid', $uuid)->firstOrFail());
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Project $project)
    {
        return $this->renderEditForm($project);
    }

    /**
     * Show the form for editing the specified resource by UUID.
     */
    public function editByUuid($uuid)
    {
        return $this->renderEditForm(Project::where('uuid', $uuid)->firstOrFail());
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Project $project)
    {
        return $this->saveProject($request, $project);
    }

    /**
     * Update the specified resource in storage by UUID.
     */
    public function updateByUuid(Request $request, $uuid)
    {
        return $this->saveProject($request, Project::where('uuid', $uuid)->firstOrFail());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project)
    {
        $user = Auth::user();

        if (!$project->canBeManagedBy($user)) {
            abort(403, 'Unauthorized');
        }

        $company = $project->company;
        $project->delete();

        // Deleted from the client company's page: go back there, on its Projects tab
        $redirect = request('from') === 'client' && $company
            ? redirect("/clients/{$company->uuid}?tab=projects")
            : redirect()->route('projects.index');

        return $redirect
            ->with('flash', [
                'message' => 'Project deleted successfully.',
                'type' => 'success'
            ]);
    }

    private function renderProject(Project $project)
    {
        $user = Auth::user();

        // Tenant check: the project must be in the user's company (and, for staff, one they are assigned to)
        if (!$project->isVisibleTo($user)) {
            abort(403, 'Unauthorized');
        }

        $project->load(['creator', 'users', 'company:id,uuid,name,manager_id', 'company.manager:id,name,email,role,deactivated_at', 'reports.reporter', 'reports.attachments']);

        return Inertia::render('admin/projects/show', [
            'project' => $project,
            'canManage' => $project->canBeManagedBy($user),
        ]);
    }

    private function renderEditForm(Project $project)
    {
        $user = Auth::user();

        if (!$project->canBeManagedBy($user)) {
            abort(403, 'Unauthorized');
        }

        $project->load(['users', 'company:id,uuid,name']);

        return Inertia::render('admin/projects/edit', [
            'project' => $project,
            // Only staff of the project's own company can be put on it
            'clients' => $this->staffOfCompany($project->company_id),
        ]);
    }

    private function saveProject(Request $request, Project $project)
    {
        $user = Auth::user();

        if (!$project->canBeManagedBy($user)) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:projects,name,' . $project->id,
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive,completed',
            'client_ids' => 'array',
            'client_ids.*' => 'exists:users,id',
        ]);

        $memberIds = $this->validatedMemberIds($validated['client_ids'] ?? [], $project->company_id);

        // Track changes for notifications
        $changes = [];
        foreach (['name', 'description', 'status'] as $field) {
            $newValue = $validated[$field] ?? null;
            if ($project->{$field} !== $newValue) {
                $changes[$field] = ['old' => $project->{$field}, 'new' => $newValue];
            }
        }

        $project->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
        ]);

        // If project is marked as completed, close all open reports
        $closedReportsCount = 0;
        if ($validated['status'] === 'completed' && isset($changes['status'])) {
            $closedReportsCount = $this->closeOpenReports($project, $user);
        }

        $syncData = [];
        foreach ($memberIds as $memberId) {
            $syncData[$memberId] = [
                'role' => 'member',
                'assigned_by' => Auth::id(),
                'assigned_at' => now(),
            ];
        }
        $synced = $project->users()->sync($syncData);
        app(NotificationService::class)->notifyProjectAssigned($project, $user, $synced['attached']);
        $memberMessage = $this->logMembershipChange($project, $synced['attached'], $synced['detached']);

        // Send notifications if there were changes
        if (!empty($changes)) {
            app(NotificationService::class)->notifyProjectUpdated($project, $user, $changes);
        }

        $message = 'Project updated successfully.' . $memberMessage;
        if ($closedReportsCount > 0) {
            $message .= " {$closedReportsCount} unfinished issue(s) were automatically closed.";
        }

        return redirect()->route('projects.index')
            ->with('flash', [
                'message' => $message,
                'type' => 'success'
            ]);
    }

    /**
     * Admins and managers create projects; a manager needs a company to create them in.
     */
    private function ensureCanCreateProjects(User $user): void
    {
        if (!$user->canManageProjects() || (!$user->isAdmin() && !$user->company_id)) {
            abort(403, 'Unauthorized');
        }
    }

    /**
     * Staff who may be put on a project: every company's for an admin (the form filters
     * by the chosen company), the manager's own company's otherwise.
     */
    private function assignableStaff(User $user)
    {
        return User::where('role', 'staff')
            ->whereNull('deactivated_at')
            ->whereNotNull('company_id')
            ->when(!$user->isAdmin(), fn ($query) => $query->where('company_id', $user->company_id))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'company_id']);
    }

    private function staffOfCompany(?int $companyId)
    {
        return User::where('role', 'staff')
            ->whereNull('deactivated_at')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'company_id']);
    }

    private function selectableCompanies(User $user)
    {
        return Company::where('status', 'active')
            ->when(!$user->isAdmin(), fn ($query) => $query->where('id', $user->company_id))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Only active staff of the project's own company may be members. Anyone else in the
     * request is rejected rather than silently dropped, so a mistake is visible.
     *
     * @param  array<int, int|string>  $requestedIds
     * @return array<int, int>
     */
    private function validatedMemberIds(array $requestedIds, ?int $companyId): array
    {
        $requestedIds = array_values(array_unique(array_map('intval', $requestedIds)));

        $allowedIds = User::whereIn('id', $requestedIds)
            ->where('role', 'staff')
            ->where('company_id', $companyId)
            ->whereNull('deactivated_at')
            ->pluck('id')
            ->all();

        if (count($allowedIds) !== count($requestedIds)) {
            throw ValidationException::withMessages([
                'client_ids' => 'Only active staff of this client company can be added to its projects.',
            ]);
        }

        return $allowedIds;
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
    private function logMembershipChange(Project $project, array $addedIds, array $removedIds): string
    {
        $message = '';

        foreach (['Added' => $addedIds, 'Removed' => $removedIds] as $verb => $ids) {
            if (empty($ids)) {
                continue;
            }

            // Sentence for the success message, e.g. " 2 staff members added to project successfully."
            $count = count($ids);
            $message .= ' ' . ($count === 1 ? 'Staff member' : "{$count} staff members") . ' '
                . strtolower($verb) . ' ' . ($verb === 'Added' ? 'to' : 'from') . ' project successfully.';

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

        return $message;
    }
}