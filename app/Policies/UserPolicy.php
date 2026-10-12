<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'manager']);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'manager']);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'admin']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        // Super admin can edit anyone
        if ($user->role === 'super_admin') {
            return true;
        }

        // Admin can edit non-super-admin users
        if ($user->role === 'admin' && $model->role !== 'super_admin') {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        // Cannot delete super admin or self
        if ($model->role === 'super_admin' || $model->id === $user->id) {
            return false;
        }

        // Super admin can delete anyone (except restrictions above)
        if ($user->role === 'super_admin') {
            return true;
        }

        // Admin can delete non-super-admin users
        if ($user->role === 'admin' && $model->role !== 'super_admin') {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return $this->delete($user, $model);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return $this->delete($user, $model);
    }

    /**
     * Determine whether the user can create a user with a specific role.
     */
    public function createRole(User $user, string $role): bool
    {
        // Super admin can create any role except super_admin
        if ($user->role === 'super_admin') {
            return in_array($role, ['admin', 'manager', 'developer', 'client']);
        }

        // Admin can create manager, developer, and client
        if ($user->role === 'admin') {
            return in_array($role, ['manager', 'developer', 'client']);
        }

        return false;
    }
}