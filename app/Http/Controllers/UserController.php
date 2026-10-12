<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => 'required|string|in:admin,manager,developer,client',
        ], [
            'name.required' => 'Please enter a full name.',
            'email.required' => 'Please enter an email address.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'This email address is already registered.',
            'password.required' => 'Please enter a password.',
            'password.confirmed' => 'Password confirmation does not match.',
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
            $newUser = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'created_by' => auth()->id(),
                'email_verified_at' => now(),
            ]);

            app(NotificationService::class)->notifyAccountCreated($newUser, $currentUser);

            return redirect()->route('users.index')
                ->with('flash', [
                    'message' => "User '{$newUser->name}' created successfully as {$newUser->role}.",
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