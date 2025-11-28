<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Project extends Model
{
    protected $fillable = [
        'name',
        'description',
        'status',
        'created_by',
        'uuid',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($project) {
            if (empty($project->uuid)) {
                $project->uuid = Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_users')
            ->withPivot(['role', 'assigned_at', 'assigned_by'])
            ->withTimestamps();
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_users')
            ->where('role', 'client')
            ->withPivot(['role', 'assigned_at', 'assigned_by'])
            ->withTimestamps();
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
