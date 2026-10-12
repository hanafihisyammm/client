<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Report;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Client companies. Admins manage all of them; a manager can open only their own.
 */
class CompanyController extends Controller
{
    /**
     * List every client company (admins only).
     */
    public function index()
    {
        $this->ensureAdmin();

        $companies = Company::with('manager:id,uuid,name,email,deactivated_at')
            ->withCount([
                'staff',
                'staff as active_staff_count' => fn ($query) => $query->whereNull('deactivated_at'),
                'projects',
            ])
            ->orderBy('name')
            ->get()
            ->each(function (Company $company) {
                $company->open_issues_count = Report::whereHas('project', fn ($project) => $project->where('company_id', $company->id))
                    ->whereNotIn('status', [Report::STATUS_CLOSED])
                    ->count();
            });

        return Inertia::render('admin/clients/index', [
            'companies' => $companies,
            'availableManagers' => $this->availableManagers(),
        ]);
    }

    /**
     * Create a client company together with its one manager.
     */
    public function store(Request $request)
    {
        $this->ensureAdmin();

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:companies,name',
            'manager_id' => 'nullable|integer|exists:users,id',
            'manager_email' => 'nullable|string|email|max:255',
        ], [
            'name.unique' => 'A client company with this name already exists.',
        ]);

        $company = DB::transaction(function () use ($validated) {
            $company = Company::create([
                'name' => $validated['name'],
                'status' => 'active',
                'created_by' => Auth::id(),
            ]);

            $this->assignManager($company, $validated['manager_id'] ?? null, $validated['manager_email'] ?? null);

            return $company;
        });

        return redirect()->route('clients.show', $company)
            ->with('flash', [
                'message' => "Client company '{$company->name}' created successfully.",
                'type' => 'success',
            ]);
    }

    /**
     * The company page: its manager, staff, projects and counts.
     */
    public function show(Company $company)
    {
        $user = Auth::user();

        // Tenant check: a manager can only open their own company
        if (!$company->canBeViewedBy($user)) {
            abort(403, 'Unauthorized');
        }

        $company->load('manager:id,uuid,name,email,deactivated_at,email_verified_at');

        $staff = $company->staff()
            ->with('projects:id,uuid,name,status')
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'email', 'role', 'company_id', 'deactivated_at', 'email_verified_at', 'created_at']);

        $projects = $company->projects()
            ->withCount([
                'users',
                'reports',
                'reports as open_reports_count' => fn ($query) => $query->whereNotIn('status', [Report::STATUS_CLOSED]),
            ])
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'status', 'company_id', 'created_at']);

        $issues = Report::whereHas('project', fn ($project) => $project->where('company_id', $company->id));

        return Inertia::render('admin/clients/show', [
            'company' => $company,
            'staff' => $staff,
            'projects' => $projects,
            'counts' => [
                'staff' => $staff->count(),
                'active_staff' => $staff->whereNull('deactivated_at')->count(),
                'projects' => $projects->count(),
                'active_projects' => $projects->where('status', 'active')->count(),
                'issues' => (clone $issues)->count(),
                'open_issues' => (clone $issues)->whereNotIn('status', [Report::STATUS_CLOSED])->count(),
            ],
            // Only admins rename a company, change its status or change its manager
            'canManageCompany' => $user->isAdmin(),
            // Admins only: add staff, edit their details, deactivate, resend invitations
            'canManageStaff' => $company->canHaveStaffManagedBy($user),
            // Admins and this company's manager: choose which projects staff work on
            'canAssignStaff' => $company->canHaveStaffAssignedBy($user),
            // Admins and this company's manager: create, edit and delete its projects
            'canManageProjects' => $company->canBeViewedBy($user),
            'availableManagers' => $user->isAdmin() ? $this->availableManagers() : [],
        ]);
    }

    /**
     * Rename the company, change its status, or change its manager (admins only).
     */
    public function update(Request $request, Company $company)
    {
        $this->ensureAdmin();

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:companies,name,' . $company->id,
            'status' => 'required|in:active,inactive',
            'manager_id' => 'nullable|integer|exists:users,id',
            'manager_email' => 'nullable|string|email|max:255',
        ], [
            'name.unique' => 'A client company with this name already exists.',
        ]);

        $managerChanged = false;

        DB::transaction(function () use ($company, $validated, &$managerChanged) {
            $company->update(['name' => $validated['name'], 'status' => $validated['status']]);

            $wantsNewManager = !empty($validated['manager_email'])
                || (!empty($validated['manager_id']) && (int) $validated['manager_id'] !== $company->manager_id);

            if ($wantsNewManager) {
                $this->assignManager($company, $validated['manager_id'] ?? null, $validated['manager_email'] ?? null);
                $managerChanged = true;
            }
        });

        return redirect()->route('clients.show', $company)
            ->with('flash', [
                'message' => $managerChanged
                    ? 'Client company updated and manager changed successfully.'
                    : 'Client company updated successfully.',
                'type' => 'success',
            ]);
    }

    /**
     * Send a manager to their own company's page.
     */
    public function mine()
    {
        $user = Auth::user();

        if (!$user->isManager() || !$user->company) {
            abort(403, 'Unauthorized');
        }

        return redirect()->route('clients.show', $user->company);
    }

    private function ensureAdmin(): void
    {
        if (!Auth::user()->isAdmin()) {
            abort(403, 'Unauthorized');
        }
    }

    /**
     * Managers who are not in charge of any company yet.
     */
    private function availableManagers()
    {
        return User::where('role', 'manager')
            ->whereNull('deactivated_at')
            ->whereNotIn('id', Company::whereNotNull('manager_id')->pluck('manager_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /**
     * Put one manager in charge of the company: an existing manager who has no company,
     * or a new manager account invited by email. The previous manager, if any, is left
     * without a company (and so without access) until an admin places them elsewhere.
     */
    private function assignManager(Company $company, ?int $managerId, ?string $managerEmail): void
    {
        $manager = null;
        $isNew = false;

        if ($managerEmail) {
            $manager = User::where('email', $managerEmail)->first();

            if (!$manager) {
                $manager = User::create([
                    'name' => Str::title(trim(preg_replace('/[._+\-]+/', ' ', Str::before($managerEmail, '@')))),
                    'email' => $managerEmail,
                    'password' => Hash::make(Str::random(40)),
                    'role' => 'manager',
                    'created_by' => Auth::id(),
                    'email_verified_at' => null,
                ]);
                $isNew = true;
            }
        } elseif ($managerId) {
            $manager = User::find($managerId);
        }

        if (!$manager) {
            throw ValidationException::withMessages([
                'manager_email' => 'Choose an existing manager or enter an email address to invite a new one.',
            ]);
        }

        if (!$manager->isManager() || !$manager->isActive()) {
            throw ValidationException::withMessages([
                $managerEmail ? 'manager_email' : 'manager_id' => 'This person is not an active manager, so they cannot be put in charge of a company.',
            ]);
        }

        // One manager, one company
        $otherCompany = Company::where('manager_id', $manager->id)->where('id', '!=', $company->id)->first();
        if ($otherCompany) {
            throw ValidationException::withMessages([
                $managerEmail ? 'manager_email' : 'manager_id' => "{$manager->name} already manages {$otherCompany->name}. A manager can only be in charge of one company.",
            ]);
        }

        if ($company->manager_id && $company->manager_id !== $manager->id) {
            User::where('id', $company->manager_id)->first()?->update(['company_id' => null]);
        }

        $manager->update(['company_id' => $company->id]);
        $company->update(['manager_id' => $manager->id]);

        if ($isNew) {
            app(NotificationService::class)->notifyUserInvited($manager, Auth::user());
        }
    }
}
