<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivated users cannot use the app. This also ends a session that was
 * already open when the account was deactivated.
 */
class EnsureUserIsActive
{
    public const MESSAGE = 'This account has been deactivated. Please contact your manager or administrator.';

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->isActive()) {
            \App\Support\PersistentLogins::revokeAll($user);
            $user->pushSubscriptions()->delete();
            \App\Support\PersistentLogins::forgetCookie();
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => self::MESSAGE], 403);
            }

            return redirect()->route('login')->with('status', self::MESSAGE);
        }

        return $next($request);
    }
}
