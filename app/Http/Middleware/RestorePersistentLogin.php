<?php

namespace App\Http\Middleware;

use App\Support\PersistentLogins;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Remember me" (see App\Support\PersistentLogins): signs a remembered browser back in when
 * its session has ended, and signs a session out as soon as its remembered login has been
 * ended elsewhere (a password change, deactivation). Runs before anything asks who the user is.
 */
class RestorePersistentLogin
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // API calls made with a personal access token have no session and are not remembered
        if (! $request->hasSession()) {
            return $next($request);
        }

        $guard = Auth::guard('web');
        $session = $request->session();

        if ($session->has($guard->getName())) {
            if ($session->has(PersistentLogins::SESSION_KEY) && ! PersistentLogins::check($request)) {
                return $this->signOut($request);
            }
        } elseif ($request->cookies->has(PersistentLogins::cookieName())) {
            PersistentLogins::restore($request);
        }

        // Laravel's own "Remember me" cookie, given out before this existed: carry on with
        // the person signed in, remembered the new way from now on
        if (! $session->has(PersistentLogins::SESSION_KEY)
            && $request->cookies->has($guard->getRecallerName())
            && ($user = $guard->user())
            && $guard->viaRemember()
            && $user->isActive()) {
            PersistentLogins::start($request, $user);
            Cookie::queue(Cookie::forget($guard->getRecallerName()));
        }

        return $next($request);
    }

    private function signOut(Request $request): Response
    {
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        PersistentLogins::forgetCookie();

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect()->route('login');
    }
}
