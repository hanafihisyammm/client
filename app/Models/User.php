<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

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

    public function isClient(): bool
    {
        return $this->role === 'client';
    }


    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'super_admin']);
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
