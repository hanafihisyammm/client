<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BypassCsrfForLogin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Temporarily bypass CSRF for login route to debug authentication
        if ($request->is('login') && $request->isMethod('POST')) {
            $request->session()->put('_token', $request->header('X-CSRF-TOKEN') ?: $request->input('_token'));
        }
        
        return $next($request);
    }
}
