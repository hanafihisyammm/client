<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use NotificationChannels\WebPush\PushSubscription as BasePushSubscription;

/**
 * A browser or installed app that receives notifications as phone / desktop alerts.
 * Linked to the browser's "Remember me" login when it has one, and ends with it.
 */
class PushSubscription extends BasePushSubscription
{
    use MassPrunable;

    protected $fillable = [
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
        'persistent_login_id',
    ];

    /**
     * Browsers that were never remembered and have not opened the app for the "Remember me"
     * idle period (php artisan model:prune). Remembered ones end with their login.
     */
    public function prunable(): Builder
    {
        return static::whereNull('persistent_login_id')
            ->where('updated_at', '<', now()->subDays((int) config('auth.persistent_login.idle_days')));
    }
}
