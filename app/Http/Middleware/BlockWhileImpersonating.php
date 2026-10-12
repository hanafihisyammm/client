<?php

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Super Admin signed in as someone else can use the app as that person,
 * but must not change that person's own account: their name, email or password,
 * or delete it.
 */
class BlockWhileImpersonating
{
    public const MESSAGE = 'You are signed in as another user, so their account settings cannot be changed. Return to Super Admin first.';

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Impersonation::isActive()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => self::MESSAGE], 403);
            }

            return back()->with('flash', ['message' => self::MESSAGE, 'type' => 'error']);
        }

        return $next($request);
    }
}
