<?php

namespace App\Support;

use App\Models\User;

/**
 * "Sign in as": a Super Admin temporarily using the app as another user.
 * The only thing that marks it is the original Super Admin's id kept in the session;
 * the user being signed in as is never changed.
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonator_id';

    /**
     * Whether the current session is a Super Admin signed in as someone else.
     */
    public static function isActive(): bool
    {
        return self::impersonatorId() !== null;
    }

    public static function impersonatorId(): ?int
    {
        // Console commands, queue workers and requests without a session never impersonate
        if (app()->runningInConsole() && !app()->runningUnitTests()) {
            return null;
        }

        $request = request();
        if (!$request->hasSession()) {
            return null;
        }

        $id = $request->session()->get(self::SESSION_KEY);

        return $id === null ? null : (int) $id;
    }

    /**
     * The Super Admin behind the current session, if any.
     */
    public static function impersonator(): ?User
    {
        $id = self::impersonatorId();

        return $id === null ? null : User::find($id);
    }
}
