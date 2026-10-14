<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            if (empty($user->uuid)) {
                $user->uuid = Str::uuid();
            }
        });
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'company_id',
        'deactivated_at',
        'created_by',
        'uuid',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => 'string',
            'deactivated_at' => 'datetime',
        ];
    }

    public function reportedReports(): HasMany
    {
        return $this->hasMany(Report::class, 'reported_by');
    }

    public function assignedReports(): HasMany
    {
        return $this->hasMany(Report::class, 'assigned_to');
    }

    /**
     * Super admins and admins work across every company.
     */
    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'super_admin']);
    }

    /**
     * A manager runs exactly one company and can only see that company's data.
     */
    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    /**
     * Staff belong to one company and work in the projects they are assigned to.
     */
    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /**
     * Whether this person has left anything in the system that deleting them would orphan:
     * issues, comments, activity, time entries or projects. Such people are deactivated, not deleted.
     */
    public function hasWork(): bool
    {
        $db = \Illuminate\Support\Facades\DB::class;

        return $db::table('reports')->where('reported_by', $this->id)->exists()
            || $db::table('report_comments')->where('user_id', $this->id)->exists()
            || $db::table('report_activity_logs')->where('user_id', $this->id)->exists()
            || $db::table('time_entries')->where('user_id', $this->id)->exists()
            || $db::table('projects')->where('created_by', $this->id)->exists();
    }

    public function canManageProjects(): bool
    {
        return $this->isAdmin() || $this->isManager();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Ids of the projects this user has been assigned to.
     */
    public function projectIds(): \Illuminate\Support\Collection
    {
        return \Illuminate\Support\Facades\DB::table('project_users')
            ->where('user_id', $this->id)
            ->pluck('project_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_users')
            ->withPivot(['role', 'assigned_at', 'assigned_by'])
            ->withTimestamps();
    }

    public function createdProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'created_by');
    }
}
