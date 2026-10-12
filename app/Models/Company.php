<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A client company. It is the tenant boundary: its manager and staff
 * can only ever see this company's projects and issues.
 */
class Company extends Model
{
    protected $fillable = [
        'name',
        'manager_id',
        'status',
        'created_by',
        'uuid',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($company) {
            if (empty($company->uuid)) {
                $company->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * Everyone who belongs to the company: its manager and its staff.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'staff');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Admins see every company; a manager only their own. Staff do not manage companies.
     */
    public function canBeViewedBy(User $user): bool
    {
        return $user->isAdmin() || ($user->isManager() && $user->company_id === $this->id);
    }

    /**
     * Whether this user may add staff accounts, edit their details, deactivate
     * or reactivate them and resend invitations. Admins only.
     */
    public function canHaveStaffManagedBy(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Whether this user may choose which of the company's projects its staff work on.
     * This is all a manager may do with staff; they cannot add or change accounts.
     */
    public function canHaveStaffAssignedBy(User $user): bool
    {
        return $this->canBeViewedBy($user);
    }
}
