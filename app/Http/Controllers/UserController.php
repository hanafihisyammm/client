<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class UserController extends Controller
{
    use AuthorizesRequests;
    /**
     * Display a listing of the users.
     */
    public function index()
    {
        $this->authorize('viewAny', User::class);

        $users = User::orderBy('created_at', 'desc')->get();

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

        $currentUser = auth()->user();
        
        // Determine available roles based on current user's permissions
        $availableRoles = [];
        if ($currentUser->role === 'super_admin') {
            $availableRoles = [
                ['value' => 'admin', 'label' => 'Admin'],
                ['value' => 'manager', 'label' => 'Manager'],
                ['value' => 'developer', 'label' => 'Developer'],
                ['value' => 'client', 'label' => 'Client']
            ];
        } else if ($currentUser->role === 'admin') {
            $availableRoles = [
                ['value' => 'manager', 'label' => 'Manager'],
                ['value' => 'developer', 'label' => 'Developer'],
                ['value' => 'client', 'label' => 'Client']
            ];
        }

        return Inertia::render('admin/users/create', [
            'availableRoles' => $availableRoles
        ]);
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        // Custom validation for role restrictions
        // Only the email and role are entered here; the user sets their own name and password from the invitation email
        $validated = $request->validate([
            'email' => 'required|string|email|max:255|unique:users',
            'role' => 'required|string|in:admin,manager,developer,client',
        ], [
            'email.required' => 'Please enter an email address.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'This email address is already registered.',
            'role.required' => 'Please select a user role.',
            'role.in' => 'Please select a valid role.',
        ]);

        // Check if the current user can create a user with the requested role
        $currentUser = auth()->user();
        if (!$currentUser->can('createRole', [User::class, $validated['role']])) {
            return back()
                ->withInput()
                ->withErrors(['role' => 'You are not authorized to create users with this role. Admins can only create Client accounts.']);
        }

        try {
            // The account stays "Pending" (no verified date, unusable password) until the invitation is accepted
            $newUser = User::create([
                // Placeholder name from the email ("jane.tan@..." becomes "Jane Tan") until the user enters their own
                'name' => Str::title(trim(preg_replace('/[._+\-]+/', ' ', Str::before($validated['email'], '@')))),
                'email' => $validated['email'],
                'password' => Hash::make(Str::random(40)),
                'role' => $validated['role'],
                'created_by' => auth()->id(),
                'email_verified_at' => null,
            ]);

            app(NotificationService::class)->notifyUserInvited($newUser, $currentUser);

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
            'user' => $user
        ]);
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit(User $user)
    {
        $this->authorize('update', $user);

        return Inertia::render('admin/users/edit', [
            'user' => $user
        ]);
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'role' => 'required|string|in:admin,manager,developer,client',
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
        ]);

        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'role' => $request->role,
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        return redirect()->route('users.index')
            ->with('flash', [
                'message' => 'User updated successfully.',
                'type' => 'success'
            ]);
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(User $user)
    {
        $this->authorize('delete', $user);

        // Prevent deletion of super admin and self
        if ($user->role === 'super_admin' || $user->id === auth()->id()) {
            return redirect()->route('users.index')
                ->with('flash', [
                    'message' => 'Cannot delete this user.',
                    'type' => 'error'
                ]);
        }

        $user->delete();

        return redirect()->route('users.index')
            ->with('flash', [
                'message' => 'User deleted successfully.',
                'type' => 'success'
            ]);
    }
}