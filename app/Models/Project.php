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
        'prefix',
        'description',
        'status',
        'created_by',
        'uuid',
        'next_issue_number',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($project) {
            if (empty($project->uuid)) {
                $project->uuid = Str::uuid();
            }

            // Generate prefix if not set
            if (empty($project->prefix)) {
                $project->prefix = $project->generatePrefix();
            }
        });
    }

    /**
     * Generate a prefix from the project name
     */
    public function generatePrefix(): string
    {
        $name = $this->name ?? 'Project';
        $words = preg_split('/[\s\-_]+/', preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $name));

        if (count($words) === 1) {
            $basePrefix = strtoupper(substr($words[0], 0, 4));
        } else {
            $basePrefix = '';
            foreach (array_slice($words, 0, 4) as $word) {
                if (!empty($word)) {
                    $basePrefix .= strtoupper($word[0]);
                }
            }
        }

        $prefix = $basePrefix ?: 'PROJ';

        // Make unique by checking existing prefixes
        $counter = 1;
        $uniquePrefix = $prefix;
        while (self::where('prefix', $uniquePrefix)->where('id', '!=', $this->id ?? 0)->exists()) {
            $uniquePrefix = $prefix . $counter;
            $counter++;
        }

        return $uniquePrefix;
    }

    /**
     * Get the next issue key and increment the counter
     */
    public function getNextIssueKey(): string
    {
        $issueKey = $this->prefix . '-' . $this->next_issue_number;

        $this->increment('next_issue_number');

        return $issueKey;
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

    public function labels(): HasMany
    {
        return $this->hasMany(Label::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
