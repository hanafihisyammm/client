<?php

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Someone using a password an admin gave them can do nothing else until they choose their own.
 * A Super Admin signed in as them is let through: they may not change that person's password,
 * so they would otherwise be stuck.
 */
class EnsurePasswordIsChanged
{
    public const MESSAGE = 'Please choose a new password before continuing.';

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            !$user?->must_change_password
            || $request->routeIs('password.change', 'password.change.update', 'logout')
            || Impersonation::isActive()
        ) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        return redirect()->route('password.change');
    }
}
