<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Staff accounts of one client company. Admins add, edit and deactivate them;
 * the company's own manager may only choose which projects they work on.
 * Every action re-checks that the staff member really belongs to the company in the URL.
 */
class StaffController extends Controller
{
    /**
     * Create a staff login for the company. They receive an email to set their own password.
     */
    public function store(Request $request, Company $company)
    {
        $actor = $this->authorizeFor($company);

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'project_ids' => 'array',
            'project_ids.*' => 'integer',
        ], [
            'email.unique' => 'This email address is already registered.',
        ]);

        $projectIds = $this->validatedProjectIds($company, $validated['project_ids'] ?? []);

        // The role and company come from here, never from the request
        $staff = User::create([
            'name' => ($validated['name'] ?? null) ?: Str::title(trim(preg_replace('/[._+\-]+/', ' ', Str::before($validated['email'], '@')))),
            'email' => $validated['email'],
            'password' => Hash::make(Str::random(40)),
            'role' => 'staff',
            'company_id' => $company->id,
            'created_by' => $actor->id,
            'email_verified_at' => null,
        ]);

        $this->syncProjects($company, $staff, $projectIds, $actor);
        app(NotificationService::class)->notifyUserInvited($staff, $actor);

        return back()->with('flash', [
            'message' => "Staff account created. Invitation sent to {$staff->email}.",
            'type' => 'success',
        ]);
    }

    /**
     * Change the projects a staff member is assigned to. Admins can also edit their details.
     */
    public function update(Request $request, Company $company, User $user)
    {
        $actor = $this->authorizeFor($company, $user, assignOnly: true);
        $canEditDetails = $company->canHaveStaffManagedBy($actor);

        $rules = ['project_ids' => 'array', 'project_ids.*' => 'integer'];

        // A manager only assigns projects, so a name or email they send is never read
        if ($canEditDetails) {
            $rules['name'] = 'required|string|max:255';
            $rules['email'] = 'required|string|email|max:255|unique:users,email,' . $user->id;
        }

        $validated = $request->validate($rules, [
            'email.unique' => 'This email address is already registered.',
        ]);

        $projectIds = $this->validatedProjectIds($company, $validated['project_ids'] ?? []);

        if ($canEditDetails) {
            $user->update(['name' => $validated['name'], 'email' => $validated['email']]);
        }

        $this->syncProjects($company, $user, $projectIds, $actor);

        return back()->with('flash', [
            'message' => $canEditDetails ? 'Staff member updated successfully.' : "Projects updated for {$user->name}.",
            'type' => 'success',
        ]);
    }

    /**
     * Stop a staff member logging in. Their issues, comments and history are kept.
     */
    public function deactivate(Company $company, User $user)
    {
        $this->authorizeFor($company, $user);

        if ($user->isActive()) {
            $user->update(['deactivated_at' => now()]);
        }

        return back()->with('flash', [
            'message' => "{$user->name} has been deactivated and can no longer log in.",
            'type' => 'success',
        ]);
    }

    public function reactivate(Company $company, User $user)
    {
        $this->authorizeFor($company, $user);

        $user->update(['deactivated_at' => null]);

        return back()->with('flash', [
            'message' => "{$user->name} has been reactivated.",
            'type' => 'success',
        ]);
    }

    /**
     * Send the "set up your account" email again to staff who have not finished setting up.
     */
    public function resendInvitation(Company $company, User $user)
    {
        $actor = $this->authorizeFor($company, $user);

        if ($user->email_verified_at !== null) {
            return back()->withErrors(['invitation' => 'This person has already set up their account.']);
        }

        app(NotificationService::class)->notifyUserInvited($user, $actor);

        return back()->with('flash', [
            'message' => "Invitation sent to {$user->email}.",
            'type' => 'success',
        ]);
    }

    /**
     * Only admins manage staff accounts; the company's own manager may only assign
     * staff to projects. Only staff of this exact company can be acted on.
     */
    private function authorizeFor(Company $company, ?User $staff = null, bool $assignOnly = false): User
    {
        $actor = Auth::user();

        $allowed = $assignOnly ? $company->canHaveStaffAssignedBy($actor) : $company->canHaveStaffManagedBy($actor);

        if (!$allowed) {
            abort(403, 'Unauthorized');
        }

        if ($staff && (!$staff->isStaff() || $staff->company_id !== $company->id)) {
            abort(404);
        }

        return $actor;
    }

    /**
     * Staff can only be put on projects that belong to their own company.
     *
     * @param  array<int, int|string>  $requestedIds
     * @return array<int, int>
     */
    private function validatedProjectIds(Company $company, array $requestedIds): array
    {
        $requestedIds = array_values(array_unique(array_map('intval', $requestedIds)));

        $allowedIds = Project::whereIn('id', $requestedIds)
            ->where('company_id', $company->id)
            ->pluck('id')
            ->all();

        if (count($allowedIds) !== count($requestedIds)) {
            throw ValidationException::withMessages([
                'project_ids' => 'Staff can only be assigned to projects that belong to this client company.',
            ]);
        }

        return $allowedIds;
    }

    /**
     * @param  array<int, int>  $projectIds
     */
    private function syncProjects(Company $company, User $staff, array $projectIds, User $actor): void
    {
        $syncData = [];
        foreach ($projectIds as $projectId) {
            $syncData[$projectId] = ['role' => 'member', 'assigned_by' => $actor->id, 'assigned_at' => now()];
        }

        $before = $staff->projects()->pluck('projects.id')->all();
        $staff->projects()->sync($syncData);

        $added = array_values(array_diff($projectIds, $before));
        $removed = array_values(array_diff($before, $projectIds));

        foreach (Project::whereIn('id', $added)->get() as $project) {
            app(NotificationService::class)->notifyProjectAssigned($project, $actor, [$staff->id]);
            AuditLog::record('project.members_added', "Added {$staff->name} to project \"{$project->name}\"", 'project', $project->id, $project->name);
        }

        foreach (Project::whereIn('id', $removed)->get() as $project) {
            AuditLog::record('project.members_removed', "Removed {$staff->name} from project \"{$project->name}\"", 'project', $project->id, $project->name);
        }
    }
}
