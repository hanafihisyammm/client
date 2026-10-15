<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RestorePersistentLogin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            ForceHttps::class,
            // "Remember me", before anything asks who the user is
            RestorePersistentLogin::class,
            // Ends a session as soon as the account's password has been changed elsewhere
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            // No AddLinkHeadersForPreloadedAssets: the page already lists its preloads in the HTML,
            // and the header (one entry per script) pushed responses past nginx's 4 KB header
            // limit when "Remember me" also set its cookies, which showed as a 502 Bad Gateway
            EnsureUserIsActive::class,
        ]);

        // Laravel moves authentication ahead of the group's other middleware, so "Remember me"
        // has to be listed ahead of it too, or a remembered browser would be turned away first
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: RestorePersistentLogin::class,
        );

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
        $middleware->api(append: [
            RestorePersistentLogin::class,
            EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle CSRF token mismatch (419 error) gracefully
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your session has expired. Please refresh the page and try again.',
                    'error' => 'token_mismatch'
                ], 419);
            }
            
            // For web requests, show the custom 419 page
            return response()->view('errors.419', [], 419);
        });
    })->create();
