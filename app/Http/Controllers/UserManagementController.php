<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UserManagementController extends Controller
{
    public function index()
    {
        // Only super_admin and admin can access user management
        abort_unless(in_array(auth()->user()->role, ['super_admin', 'admin']), 403);

        $users = User::when(auth()->user()->role === 'admin', function ($query) {
            // Admins can only see users in their system (same admin who created them)
            $query->where('created_by', auth()->id())
                  ->orWhere('id', auth()->id());
        })->orderBy('created_at', 'desc')->get();

        return Inertia::render('user-management', [
            'users' => $users,
            'currentUser' => auth()->user(),
        ]);
    }

    public function store(Request $request)
    {
        $currentUser = auth()->user();
        
        // Only super_admin and admin can create users
        abort_unless(in_array($currentUser->role, ['super_admin', 'admin']), 403);

        // Determine allowed roles based on current user
        $allowedRoles = match ($currentUser->role) {
            'super_admin' => ['admin', 'manager', 'developer', 'client'],
            'admin' => ['manager', 'developer', 'client'],
            default => [],
        };

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'role' => ['required', Rule::in($allowedRoles)],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
            'created_by' => $currentUser->id,
        ]);

        return back()->with('message', 'User created successfully!');
    }

    public function update(Request $request, User $user)
    {
        $currentUser = auth()->user();
        
        // Only super_admin and admin can update users
        abort_unless(in_array($currentUser->role, ['super_admin', 'admin']), 403);

        // Admins can only update users they created
        if ($currentUser->role === 'admin' && $user->created_by !== $currentUser->id) {
            abort(403);
        }

        // Cannot update super_admin users unless you are super_admin
        if ($user->role === 'super_admin' && $currentUser->role !== 'super_admin') {
            abort(403);
        }

        $allowedRoles = match ($currentUser->role) {
            'super_admin' => ['admin', 'manager', 'developer', 'client'],
            'admin' => ['manager', 'developer', 'client'],
            default => [],
        };

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'role' => ['required', Rule::in($allowedRoles)],
        ]);

        $user->update($validated);

        return back()->with('message', 'User updated successfully!');
    }

    public function destroy(User $user)
    {
        $currentUser = auth()->user();
        
        // Only super_admin and admin can delete users
        abort_unless(in_array($currentUser->role, ['super_admin', 'admin']), 403);

        // Cannot delete yourself
        if ($user->id === $currentUser->id) {
            abort(403, 'Cannot delete your own account');
        }

        // Admins can only delete users they created
        if ($currentUser->role === 'admin' && $user->created_by !== $currentUser->id) {
            abort(403);
        }

        // Cannot delete super_admin users unless you are super_admin
        if ($user->role === 'super_admin' && $currentUser->role !== 'super_admin') {
            abort(403);
        }

        $user->delete();

        return back()->with('message', 'User deleted successfully!');
    }
}
