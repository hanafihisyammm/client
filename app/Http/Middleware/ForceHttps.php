<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ForceHttps
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (env('APP_FORCE_HTTPS', false)) {
            URL::forceScheme('https');
            
            if (!$request->isSecure() && !$request->is('api/*')) {
                return redirect()->secure($request->getRequestUri());
            }
        }

        return $next($request);
    }
}
