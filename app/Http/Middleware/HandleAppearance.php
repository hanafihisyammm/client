<?php

namespace App\Http\Middleware;

use App\Support\Appearance;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appearance = Appearance::for($request);

        View::share('appearance', $appearance);

        // Someone logging in on a new browser brings their saved theme with them
        if ($request->user() && $request->cookie(Appearance::COOKIE) !== $appearance) {
            Appearance::remember($appearance);
        }

        return $next($request);
    }
}
