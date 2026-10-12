<?php

namespace App\Models;

use App\Support\Impersonation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'user_name',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'description',
        'changes',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record an entry. The actor defaults to the logged-in user; entries
     * written outside a request (seeders, commands) have no actor.
     *
     * @param  array<string, array{old: mixed, new: mixed}>|null  $changes
     */
    public static function record(
        string $action,
        string $description,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $subjectLabel = null,
        ?array $changes = null,
        ?User $actor = null
    ): self {
        // What a Super Admin does while signed in as someone else is recorded under that
        // user, as the app sees it, with a note saying who was really behind it
        if ($actor === null && ($impersonator = Impersonation::impersonator())) {
            $description .= " (by {$impersonator->name}, signed in as this user)";
        }

        $actor ??= Auth::user();

        return static::create([
            'user_id' => $actor?->id,
            'user_name' => $actor?->name,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $subjectLabel,
            'description' => $description,
            'changes' => $changes ?: null,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
