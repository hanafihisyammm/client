<?php

namespace App\Http\Middleware;

use App\Support\Appearance;
use App\Support\Impersonation;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                // The company is included so the menu can link a manager to their own company page
                'user' => $request->user()?->loadMissing('company:id,uuid,name'),
            ],
            // "light", "dark" or "system": lets the page follow the user's saved theme
            // as soon as they log in, without waiting for a full page load
            'appearance' => fn () => Appearance::for($request),
            // Set while a Super Admin is signed in as this user, so every page can show the banner
            'impersonation' => fn () => ($superAdmin = Impersonation::impersonator())
                ? ['superAdmin' => $superAdmin->name]
                : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'csrfToken' => csrf_token(),
            // One-off success/error message set by the previous action. The id lets the page
            // show each message once, even if the browser restores this page from history.
            'flash' => function () use ($request) {
                $flash = $request->session()->get('flash');

                return is_array($flash) && !empty($flash['message'])
                    ? ['id' => (string) \Illuminate\Support\Str::uuid(), 'message' => $flash['message'], 'type' => $flash['type'] ?? 'success']
                    : null;
            },
        ];
    }
}
