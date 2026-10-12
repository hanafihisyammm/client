<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * The Users page, for admins: every account across every company.
 */
class UserController extends Controller
{
    /** How many people can be invited, or re-invited, in one go. */
    private const MAX_BULK_INVITES = 50;

    use AuthorizesRequests;

    /**
     * Display a listing of the users.
     */
    public function index()
    {
        $this->authorize('viewAny', User::class);

        $users = User::with('company:id,uuid,name')->orderBy('created_at', 'desc')->get();

        return Inertia::render('admin/users/index', [
            'users' => $users
        ]);
    }

    /**
     * Show the form for creating a new user.
     */
    public function create()
    {
        $this->authorize('create', User::class);

        return Inertia::render('admin/users/create', [
            'availableRoles' => $this->availableRoles(),
            'companies' => Company::where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        // Only the email, role and (for staff) company are entered here; the user sets
        // their own name and password from the invitation email
        $validated = $request->validate([
            'email' => 'required|string|email|max:255|unique:users',
            'role' => 'required|string|in:admin,manager,staff',
            'company_id' => 'nullable|required_if:role,staff|exists:companies,id',
        ], [
            'email.required' => 'Please enter an email address.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'This email address is already registered.',
            'role.required' => 'Please select a user role.',
            'role.in' => 'Please select a valid role.',
            'company_id.required_if' => 'Please choose the client company this staff member belongs to.',
        ]);

        // Check if the current user can create a user with the requested role
        $currentUser = auth()->user();
        if (!$currentUser->can('createRole', [User::class, $validated['role']])) {
            return back()
                ->withInput()
                ->withErrors(['role' => 'You are not authorized to create users with this role.']);
        }

        try {
            // The account stays "Pending" (no verified date, unusable password) until the invitation is accepted
            $newUser = $this->invite($validated['email'], $validated['role'], $validated['company_id'] ?? null, $currentUser);

            return redirect()->route('users.index')
                ->with('flash', [
                    'message' => "Invitation sent to {$newUser->email}.",
                    'type' => 'success'
                ]);
        } catch (\Exception $e) {
            \Log::error('User creation failed: ' . $e->getMessage());

            return back()
                ->withInput()
                ->withErrors(['general' => 'An error occurred while creating the user. Please try again.']);
        }
    }

    /**
     * Invite several people at once, each with their own role and, for staff, their own company.
     * Nothing is created unless every row is usable, so a typo never leaves the list half done.
     * Addresses that are already registered are skipped and reported back, not treated as an error.
     */
    public function bulkStore(Request $request)
    {
        $this->authorize('create', User::class);

        $validated = $request->validate([
            'invites' => 'required|array|min:1|max:' . self::MAX_BULK_INVITES,
            'invites.*.email' => 'required|string|email|max:255',
            'invites.*.role' => 'required|string|in:admin,manager,staff',
            'invites.*.company_id' => 'nullable|required_if:invites.*.role,staff|exists:companies,id',
        ], [
            'invites.required' => 'Please add at least one person.',
            'invites.max' => 'You can invite up to ' . self::MAX_BULK_INVITES . ' people at a time.',
            'invites.*.email.required' => 'Please enter an email address.',
            'invites.*.email.email' => 'Please enter a valid email address.',
            'invites.*.role.required' => 'Please select a role.',
            'invites.*.role.in' => 'Please select a valid role.',
            'invites.*.company_id.required_if' => 'Please choose the client company for this staff member.',
        ]);

        $currentUser = auth()->user();
        $invites = collect($validated['invites'])->map(fn (array $invite) => [
            'email' => Str::lower(trim($invite['email'])),
            'role' => $invite['role'],
            'company_id' => $invite['company_id'] ?? null,
        ]);

        // Row-by-row checks that validation rules cannot express
        $errors = [];
        $seen = [];
        foreach ($invites as $index => $invite) {
            if (isset($seen[$invite['email']])) {
                $errors["invites.{$index}.email"] = 'This address is listed more than once.';
            }
            $seen[$invite['email']] = true;

            if (!$currentUser->can('createRole', [User::class, $invite['role']])) {
                $errors["invites.{$index}.role"] = 'You are not authorized to create users with this role.';
            }
        }

        if ($errors) {
            return back()->withInput()->withErrors($errors);
        }

        $alreadyRegistered = User::whereIn(DB::raw('lower(email)'), $invites->pluck('email')->all())
            ->pluck('email')
            ->map(fn ($email) => Str::lower($email));
        $toInvite = $invites->reject(fn (array $invite) => $alreadyRegistered->contains($invite['email']))->values();

        if ($toInvite->isEmpty()) {
            return back()->withInput()->withErrors([
                'invites' => $invites->count() === 1 ? 'This email address is already registered.' : 'All of these email addresses are already registered.',
            ]);
        }

        DB::transaction(function () use ($toInvite, $currentUser) {
            foreach ($toInvite as $invite) {
                $this->invite($invite['email'], $invite['role'], $invite['company_id'], $currentUser);
            }
        });

        $message = $toInvite->count() === 1
            ? "Invitation sent to {$toInvite->first()['email']}."
            : "{$toInvite->count()} invitations sent.";

        if ($alreadyRegistered->isNotEmpty()) {
            $message .= " Skipped {$alreadyRegistered->count()} already registered: " . $alreadyRegistered->implode(', ') . '.';
        }

        return redirect()->route('users.index')->with('flash', ['message' => $message, 'type' => 'success']);
    }

    /**
     * Send the invitation email again to several users who have not set up their accounts yet.
     * Anyone who has already set up, is deactivated, or may not be managed by this admin is skipped.
     */
    public function bulkResendInvitations(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $validated = $request->validate([
            'user_ids' => 'required|array|min:1|max:' . self::MAX_BULK_INVITES,
            'user_ids.*' => 'integer',
        ], [
            'user_ids.required' => 'Select at least one user.',
            'user_ids.max' => 'You can resend up to ' . self::MAX_BULK_INVITES . ' invitations at a time.',
        ]);

        $actor = auth()->user();
        $selected = User::whereIn('id', $validated['user_ids'])->get();

        $pending = $selected->filter(
            fn (User $user) => $user->email_verified_at === null && $user->isActive() && $actor->can('update', $user)
        );

        if ($pending->isEmpty()) {
            return back()->withErrors(['invitation' => 'None of the selected users are waiting to set up their account.']);
        }

        foreach ($pending as $user) {
            app(NotificationService::class)->notifyUserInvited($user, $actor);
        }

        $message = $pending->count() === 1
            ? "Invitation sent to {$pending->first()->email}."
            : "{$pending->count()} invitations resent.";

        $skipped = count(array_unique($validated['user_ids'])) - $pending->count();
        if ($skipped > 0) {
            $message .= " Skipped {$skipped} that " . ($skipped === 1 ? 'is' : 'are') . ' not waiting for an invitation.';
        }

        return back()->with('flash', ['message' => $message, 'type' => 'success']);
    }

    /**
     * Create a pending account and email its invitation.
     */
    private function invite(string $email, string $role, $companyId, User $invitedBy): User
    {
        $newUser = User::create([
            // Placeholder name from the email ("jane.tan@..." becomes "Jane Tan") until the user enters their own
            'name' => Str::title(trim(preg_replace('/[._+\-]+/', ' ', Str::before($email, '@')))),
            'email' => $email,
            'password' => Hash::make(Str::random(40)),
            'role' => $role,
            // Only staff are placed in a company here; a manager is put in charge of one from the Clients page
            'company_id' => $role === 'staff' ? $companyId : null,
            'created_by' => $invitedBy->id,
            'email_verified_at' => null,
        ]);

        app(NotificationService::class)->notifyUserInvited($newUser, $invitedBy);

        return $newUser;
    }

    /**
     * Send the invitation email again to a user who has not set up their account yet.
     */
    public function resendInvitation(User $user)
    {
        $this->authorize('update', $user);

        if ($user->email_verified_at !== null) {
            return back()->withErrors(['invitation' => 'This user has already set up their account.']);
        }

        app(NotificationService::class)->notifyUserInvited($user, auth()->user());

        return back()->with('flash', [
            'message' => "Invitation sent to {$user->email}.",
            'type' => 'success'
        ]);
    }

    /**
     * Display the specified user.
     */
    public function show(User $user)
    {
        $this->authorize('view', $user);

        return Inertia::render('admin/users/show', [
            'user' => $user->load('company:id,uuid,name')
        ]);
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit(User $user)
    {
        $this->authorize('update', $user);

        return Inertia::render('admin/users/edit', [
            'user' => $user->load('company:id,uuid,name'),
            'companies' => Company::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);

        // The role cannot be changed here: a manager is tied to the company they run and
        // staff to their company's projects, so changing it would leave records inconsistent
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'company_id' => $user->isStaff() ? 'required|exists:companies,id' : 'nullable',
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
        ], [
            'company_id.required' => 'Please choose the client company this staff member belongs to.',
        ]);

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $message = 'User updated successfully.';

        // Moving staff to another company takes them off the old company's projects
        if ($user->isStaff() && (int) $validated['company_id'] !== $user->company_id) {
            $data['company_id'] = (int) $validated['company_id'];
            $user->projects()->detach();
            $message = 'User updated successfully. They were moved to another company and removed from their previous projects.';
        }

        $user->update($data);

        return redirect()->route('users.index')
            ->with('flash', [
                'message' => $message,
                'type' => 'success'
            ]);
    }

    /**
     * Stop a user logging in without deleting their work.
     */
    public function deactivate(User $user)
    {
        $this->authorize('deactivate', $user);

        $user->update(['deactivated_at' => now()]);

        return back()->with('flash', [
            'message' => "{$user->name} has been deactivated and can no longer log in.",
            'type' => 'success'
        ]);
    }

    public function reactivate(User $user)
    {
        $this->authorize('deactivate', $user);

        $user->update(['deactivated_at' => null]);

        return back()->with('flash', [
            'message' => "{$user->name} has been reactivated.",
            'type' => 'success'
        ]);
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(User $user)
    {
        $this->authorize('delete', $user);

        if (Company::where('manager_id', $user->id)->exists()) {
            return redirect()->route('users.index')
                ->with('flash', [
                    'message' => 'This user manages a client company. Change the company\'s manager first.',
                    'type' => 'error'
                ]);
        }

        // Someone with issues, comments or other history is deactivated rather than deleted,
        // so nothing they did is lost. The database refuses such a delete as a second line of defence.
        $refusal = [
            'message' => "{$user->name} has issues or activity in the system and cannot be deleted. Deactivate them instead.",
            'type' => 'error'
        ];

        if ($user->hasWork()) {
            return redirect()->route('users.index')->with('flash', $refusal);
        }

        try {
            $user->delete();
        } catch (QueryException $e) {
            return redirect()->route('users.index')->with('flash', $refusal);
        }

        return redirect()->route('users.index')
            ->with('flash', [
                'message' => 'User deleted successfully.',
                'type' => 'success'
            ]);
    }

    /**
     * Roles the current user may create.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function availableRoles(): array
    {
        $roles = [
            ['value' => 'staff', 'label' => 'Staff'],
            ['value' => 'manager', 'label' => 'Manager'],
        ];

        if (auth()->user()->role === 'super_admin') {
            $roles[] = ['value' => 'admin', 'label' => 'Admin'];
        }

        return $roles;
    }
}
