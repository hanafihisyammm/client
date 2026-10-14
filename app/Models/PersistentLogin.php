<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One browser or installed app where the user ticked "Remember me".
 * See App\Support\PersistentLogins for how it is used.
 */
class PersistentLogin extends Model
{
    use MassPrunable;

    protected $guarded = [];

    protected $hidden = ['token_hash', 'pending_token_hash', 'previous_token_hash'];

    protected function casts(): array
    {
        return [
            'token_issued_at' => 'datetime',
            'pending_issued_at' => 'datetime',
            'previous_valid_until' => 'datetime',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Rows for browsers that have not been used for too long (php artisan model:prune).
     */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now());
    }
}
