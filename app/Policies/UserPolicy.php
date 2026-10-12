<?php

namespace App\Policies;

use App\Models\User;

/**
 * The Users page is for admins, who manage accounts across every company.
 * Managers manage their own staff from their company page (see StaffController).
 */
class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
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
        return $user->role === 'admin' && $model->role !== 'super_admin';
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

        return $this->update($user, $model);
    }

    /**
     * Determine whether the user can deactivate or reactivate the model.
     */
    public function deactivate(User $user, User $model): bool
    {
        return $this->delete($user, $model);
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
        // Only a super admin can create another admin
        if ($user->role === 'super_admin') {
            return in_array($role, ['admin', 'manager', 'staff']);
        }

        if ($user->role === 'admin') {
            return in_array($role, ['manager', 'staff']);
        }

        return false;
    }
}
