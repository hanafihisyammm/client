<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\PersistentLogin;
use App\Models\User;
use App\Support\PersistentLogins;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCompanies;
use Tests\TestCase;

/**
 * People stay signed in during normal use. The session lasts two hours without activity; after
 * that, or after closing the browser, "Remember me" (App\Support\PersistentLogins) signs them
 * back in without the login page, for as long as the browser keeps being used. Logging out,
 * a password change, a deactivated account and the permission checks all still apply.
 */
class StayingSignedInTest extends TestCase
{
    use BuildsCompanies;
    use RefreshDatabase;

    private const IDLE_DAYS = 90;

    public function test_session_and_remember_me_are_configured_to_expire()
    {
        // Two hours without any request ends the session on the server
        $this->assertSame(120, config('session.lifetime'));
        $this->assertFalse((bool) config('session.expire_on_close'));
        $this->assertTrue((bool) config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));

        // A remembered browser has to log in again only after this long unused
        $this->assertSame(self::IDLE_DAYS, config('auth.persistent_login.idle_days'));
    }

    public function test_cookies_are_secure_whenever_the_site_is_served_over_https()
    {
        $this->assertTrue($this->sessionConfigWith(['APP_URL' => 'https://desk.example.com'])['secure']);
        $this->assertFalse($this->sessionConfigWith(['APP_URL' => 'http://127.0.0.1:8000'])['secure']);

        // Set explicitly, the setting wins
        $this->assertFalse($this->sessionConfigWith(['APP_URL' => 'https://desk.example.com', 'SESSION_SECURE_COOKIE' => 'false'])['secure']);
    }

    public function test_remember_me_remembers_this_browser_with_a_secret_only_it_holds()
    {
        $user = User::factory()->withoutTwoFactor()->create();

        $response = $this->logIn($user, remember: true);

        $cookie = $response->getCookie(PersistentLogins::cookieName(), false);
        $this->assertNotNull($cookie, 'The "Remember me" cookie is set');
        $this->assertEqualsWithDelta(now()->addDays(self::IDLE_DAYS)->getTimestamp(), $cookie->getExpiresTime(), 5);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());

        // Laravel's own "Remember me" cookie is no longer given out
        $response->assertCookieMissing(Auth::guard('web')->getRecallerName());

        // Only a hash of the secret is kept
        [$selector, $secret] = explode('|', $this->deviceCookie($response));
        $login = PersistentLogin::sole();
        $this->assertSame($user->id, $login->user_id);
        $this->assertSame($selector, $login->selector);
        $this->assertSame(hash('sha256', $secret), $login->token_hash);
        $this->assertStringNotContainsString($secret, json_encode($login->getAttributes()));
    }

    public function test_without_remember_me_nothing_lasting_is_set()
    {
        $user = User::factory()->withoutTwoFactor()->create();

        $this->logIn($user, remember: false)
            ->assertCookieMissing(PersistentLogins::cookieName())
            ->assertCookieMissing(Auth::guard('web')->getRecallerName());

        $this->assertSame(0, PersistentLogin::count());
    }

    public function test_the_session_survives_page_reloads()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $session = $this->sessionId($this->logIn($user, remember: false));

        foreach ([1, 30, 119] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->asNewRequest()->withCookie(config('session.cookie'), $session)->get('/dashboard')->assertOk();
        }

        $this->assertAuthenticatedAs($user);
    }

    public function test_without_remember_me_the_session_genuinely_expires_after_two_idle_hours()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $session = $this->sessionId($this->logIn($user, remember: false));

        $this->travel(121)->minutes();

        $this->asNewRequest()->withCookie(config('session.cookie'), $session)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_when_the_session_expires_remember_me_signs_the_user_back_in_without_the_login_page()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $response = $this->logIn($user, remember: true);
        $session = $this->sessionId($response);
        $device = $this->deviceCookie($response);
        $logins = AuditLog::where('action', 'auth.login')->count();

        $this->travel(121)->minutes();

        $restored = $this->asNewRequest()
            ->withCookie(config('session.cookie'), $session)
            ->withDevice($device)
            ->get('/dashboard')
            ->assertOk();

        $this->assertAuthenticatedAs($user);
        // A fresh session, not the expired one
        $this->assertNotSame($session, $this->sessionId($restored));
        // It is the same login carrying on, so it is not recorded as a new one
        $this->assertSame($logins, AuditLog::where('action', 'auth.login')->count());
    }

    public function test_a_page_opened_after_the_session_expired_keeps_its_headers_small()
    {
        // Opening the app from a notification hours later loads a whole page and sets the session,
        // CSRF and "Remember me" cookies at once. With a preload Link header as well, that passed
        // nginx's default 4 KB limit for a reply's headers, and the person saw "502 Bad Gateway".
        $this->assertNotContains(
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups()['web'],
        );

        $user = User::factory()->withoutTwoFactor()->create();
        $response = $this->logIn($user, remember: true);
        $session = $this->sessionId($response);
        $device = $this->deviceCookie($response);

        $this->travel(121)->minutes();

        $restored = $this->asNewRequest()
            ->withCookie(config('session.cookie'), $session)
            ->withDevice($device)
            ->get('/dashboard')
            ->assertOk()
            ->assertHeaderMissing('Link');

        $this->assertNotNull($restored->getCookie(PersistentLogins::cookieName(), false), 'The "Remember me" cookie is renewed');
        $this->assertLessThan(3072, strlen((string) $restored->baseResponse->headers), 'Well inside nginx\'s 4 KB header buffer');
    }

    public function test_after_a_browser_restart_the_user_is_still_signed_in()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $device = $this->deviceCookie($this->logIn($user, remember: true));

        // The next morning, with the browser closed in between: no session cookie at all
        $this->travel(14)->hours();

        $this->asNewRequest()->withDevice($device)->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_pages_api_calls_are_signed_back_in_too()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $device = $this->deviceCookie($this->logIn($user, remember: true));

        $this->travel(3)->hours();

        $this->apiCall($device)->assertOk()->assertJsonPath('id', $user->id);
    }

    public function test_a_browser_in_regular_use_stays_signed_in_with_no_fixed_limit()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $device = $this->deviceCookie($this->logIn($user, remember: true));

        // Used every couple of months for over a year, never logging in again
        foreach (range(1, 7) as $visit) {
            $this->travel(60)->days();

            $response = $this->asNewRequest()->withDevice($device)->get('/dashboard')->assertOk();
            $this->assertAuthenticatedAs($user);
            $device = $this->deviceCookie($response) ?? $device;
            // ...which the browser then uses, as it would for the rest of its visit
            $this->asNewRequest()->withDevice($device)->get('/dashboard')->assertOk();
        }
    }

    public function test_a_browser_not_used_for_the_idle_period_has_to_log_in_again()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $device = $this->deviceCookie($this->logIn($user, remember: true));

        // The cookie itself would be gone by now; the server refuses it regardless
        $this->travel(self::IDLE_DAYS + 1)->days();

        $this->asNewRequest()->withDevice($device)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame(0, PersistentLogin::count());
    }

    public function test_the_secret_is_replaced_when_the_browser_is_signed_back_in()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $first = $this->deviceCookie($this->logIn($user, remember: true));

        $this->travel(3)->hours();
        $second = $this->deviceCookie($this->asNewRequest()->withDevice($first)->get('/dashboard')->assertOk());

        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
        $this->assertSame(explode('|', $first)[0], explode('|', $second)[0], 'same browser, same row');
    }

    public function test_a_lost_reply_does_not_sign_the_browser_out()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $first = $this->deviceCookie($this->logIn($user, remember: true));

        // The reply carrying the new secret never arrives (the phone lost its signal)...
        $this->travel(3)->hours();
        $this->asNewRequest()->withDevice($first)->get('/dashboard')->assertOk();

        // ...so the browser comes back with the old one, straight away and later on
        $this->asNewRequest()->withDevice($first)->get('/dashboard')->assertOk();
        $this->travel(10)->minutes();
        $again = $this->asNewRequest()->withDevice($first)->get('/dashboard')->assertOk();

        $this->assertNotNull($this->deviceCookie($again), 'it is sent the new secret again');
        $this->assertSame(1, PersistentLogin::count());
    }

    public function test_requests_sent_at_the_same_moment_do_not_sign_the_browser_out()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $first = $this->deviceCookie($this->logIn($user, remember: true));
        $this->travel(3)->hours();

        // Reopening the app: the page and its API calls all carry the same cookie
        $page = $this->asNewRequest()->withDevice($first)->get('/dashboard')->assertOk();
        $this->apiCall($first)->assertOk();
        $this->apiCall($first)->assertOk();

        // The browser moves on to the new secret while a slower request still carries the old one
        $second = $this->deviceCookie($page);
        $this->asNewRequest()->withDevice($second)->get('/dashboard')->assertOk();
        $this->apiCall($first)->assertOk();

        $this->assertSame(1, PersistentLogin::count());
    }

    public function test_a_copied_cookie_stops_working_and_ends_the_login_once_the_browser_has_moved_on()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $stolen = $this->deviceCookie($this->logIn($user, remember: true));

        // The real browser comes back the next day and gets a new secret, which it then uses
        $this->travel(1)->days();
        $second = $this->deviceCookie($this->asNewRequest()->withDevice($stolen)->get('/dashboard')->assertOk());
        $this->asNewRequest()->withDevice($second)->get('/dashboard')->assertOk();

        // Later, someone tries the copied cookie
        $this->travel(10)->minutes();
        $this->asNewRequest()->withDevice($stolen)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();

        // That ends the login altogether: the real browser logs in again, the copy is useless
        $this->assertSame(0, PersistentLogin::count());
        $this->asNewRequest()->withDevice($second)->get('/dashboard')->assertRedirect('/login');
    }

    public function test_a_made_up_or_tampered_cookie_is_refused()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        [$selector] = explode('|', $this->deviceCookie($this->logIn($user, remember: true)));

        foreach ([$selector . '|' . str_repeat('a', 64), str_repeat('b', 32) . '|' . str_repeat('a', 64), 'nonsense', $user->id . '|x|y'] as $cookie) {
            $this->asNewRequest()->withDevice($cookie)->get('/dashboard')->assertRedirect('/login');
            $this->assertGuest();
        }
    }

    public function test_logging_out_signs_this_browser_out_and_forgets_it()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $response = $this->logIn($user, remember: true);
        $session = $this->sessionId($response);
        $device = $this->deviceCookie($response);
        $otherBrowser = $this->deviceCookie($this->asNewRequest()->logIn($user, remember: true));

        $this->asNewRequest()
            ->withCookie(config('session.cookie'), $session)
            ->withDevice($device)
            ->post('/logout')
            ->assertRedirect('/')
            ->assertCookieExpired(PersistentLogins::cookieName());
        $this->assertGuest();

        // Neither the old session nor the old cookie gets back in, on a page or the API
        foreach (['/dashboard', '/settings/profile'] as $page) {
            $this->asNewRequest()
                ->withCookie(config('session.cookie'), $session)
                ->withDevice($device)
                ->get($page)
                ->assertRedirect('/login');
            $this->assertGuest();
        }
        $this->apiCall($device)->assertUnauthorized();

        // Logging out on one browser leaves the user's other browsers signed in
        $this->asNewRequest()->withDevice($otherBrowser)->get('/dashboard')->assertOk();
    }

    public function test_changing_the_password_signs_out_every_other_browser_but_not_this_one()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $here = $this->logIn($user, remember: true);
        $session = $this->sessionId($here);
        $device = $this->deviceCookie($here);

        // Two other browsers: one remembered, one with only a session
        $remembered = $this->asNewRequest()->logIn($user, remember: true);
        $sessionOnly = $this->sessionId($this->asNewRequest()->logIn($user, remember: false));
        $this->asNewRequest()->withCookie(config('session.cookie'), $sessionOnly)->get('/dashboard')->assertOk();

        $this->asNewRequest()
            ->withCookie(config('session.cookie'), $session)
            ->withDevice($device)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'a-new-Password-123',
                'password_confirmation' => 'a-new-Password-123',
            ])
            ->assertSessionHasNoErrors();

        // The other browsers are out at once, open session or not
        $this->asNewRequest()
            ->withCookie(config('session.cookie'), $this->sessionId($remembered))
            ->withDevice($this->deviceCookie($remembered))
            ->get('/dashboard')
            ->assertRedirect('/login');
        $this->asNewRequest()->withDevice($this->deviceCookie($remembered))->get('/dashboard')->assertRedirect('/login');
        $this->asNewRequest()->withCookie(config('session.cookie'), $sessionOnly)->get('/dashboard')->assertRedirect('/login');

        // This browser carries on, now and after its session has ended
        $this->asNewRequest()->withCookie(config('session.cookie'), $session)->withDevice($device)->get('/dashboard')->assertOk();
        $this->travel(3)->hours();
        $this->asNewRequest()->withDevice($device)->get('/dashboard')->assertOk();
        $this->assertSame(1, PersistentLogin::count());
    }

    public function test_changing_the_password_does_not_log_this_browser_out_of_the_api()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $session = $this->sessionId($this->logIn($user, remember: false));

        $api = fn () => $this->asNewRequest()
            ->withCredentials()
            ->withHeader('Referer', 'http://localhost/dashboard')
            ->withCookie(config('session.cookie'), $session)
            ->getJson('/api/user');

        // The API notes the password the session started with
        $api()->assertOk();

        $this->asNewRequest()
            ->withCookie(config('session.cookie'), $session)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'a-new-Password-123',
                'password_confirmation' => 'a-new-Password-123',
            ])
            ->assertSessionHasNoErrors();

        $api()->assertOk();
    }

    public function test_resetting_a_forgotten_password_signs_out_every_browser()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $first = $this->deviceCookie($this->logIn($user, remember: true));
        $second = $this->deviceCookie($this->asNewRequest()->logIn($user, remember: true));

        $this->asNewRequest()->post('/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'a-new-Password-123',
            'password_confirmation' => 'a-new-Password-123',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, PersistentLogin::count());
        foreach ([$first, $second] as $device) {
            $this->asNewRequest()->withDevice($device)->get('/dashboard')->assertRedirect('/login');
        }
    }

    public function test_a_deactivated_account_loses_access_at_once_everywhere()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $here = $this->logIn($user, remember: true);
        $other = $this->deviceCookie($this->asNewRequest()->logIn($user, remember: true));

        $user->update(['deactivated_at' => now()]);
        $this->assertSame(0, PersistentLogin::count());

        // The session that was open is ended
        $this->asNewRequest()
            ->withCookie(config('session.cookie'), $this->sessionId($here))
            ->withDevice($this->deviceCookie($here))
            ->get('/dashboard')
            ->assertRedirect(route('login'));
        $this->assertGuest();

        // No remembered browser gets back in, on a page or the API
        $this->asNewRequest()->withDevice($other)->get('/dashboard')->assertRedirect('/login');
        $this->apiCall($other)->assertUnauthorized();
        $this->assertGuest();

        // Not even once the account is reactivated: that needs a fresh login
        $user->update(['deactivated_at' => null]);
        $this->asNewRequest()->withDevice($other)->get('/dashboard')->assertRedirect('/login');
    }

    public function test_an_open_session_of_a_deactivated_account_is_told_why_it_ended()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $session = $this->sessionId($this->logIn($user, remember: false));

        $user->update(['deactivated_at' => now()]);

        $this->asNewRequest()
            ->withCookie(config('session.cookie'), $session)
            ->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', \App\Http\Middleware\EnsureUserIsActive::MESSAGE);
        $this->assertGuest();
    }

    public function test_a_deactivated_account_is_refused_even_if_its_login_somehow_survived()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $device = $this->deviceCookie($this->logIn($user, remember: true));

        // Deactivated without going through the model, so nothing revoked the login
        User::whereKey($user->id)->update(['deactivated_at' => now()]);

        $this->asNewRequest()->withDevice($device)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame(0, PersistentLogin::count());
    }

    public function test_being_signed_back_in_keeps_the_same_permissions()
    {
        [$company] = $this->makeCompany('Acme');
        $staff = $this->makeStaff($company, [], ['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null]);
        $device = $this->deviceCookie($this->logIn($staff, remember: true));
        $this->travel(3)->hours();

        $first = $this->asNewRequest()->withDevice($device)->get('/dashboard')->assertOk();
        $device = $this->deviceCookie($first);

        $this->asNewRequest()->withDevice($device)->get('/audit-logs')->assertForbidden();
        $this->asNewRequest()->withDevice($device)->get('/users')->assertForbidden();
        $this->asNewRequest()->withDevice($device)->post('/users', ['name' => 'X', 'email' => 'x@example.com'])->assertForbidden();
    }

    public function test_a_super_admin_can_still_sign_in_as_someone_and_back()
    {
        $superAdmin = $this->makeAdmin('super_admin', ['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null]);
        [, $manager] = $this->makeCompany('Acme');
        $response = $this->logIn($superAdmin, remember: true);
        $device = $this->deviceCookie($response);
        $session = $this->sessionId($response);

        $start = $this->asNewRequest()->withCookie(config('session.cookie'), $session)->withDevice($device)
            ->post('/users/' . $manager->uuid . '/impersonate')
            ->assertRedirect();
        // Signing in as someone gives the session a new id
        $session = $this->sessionId($start);

        $this->asNewRequest()->withCookie(config('session.cookie'), $session)->withDevice($device)->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($manager);

        $stop = $this->asNewRequest()->withCookie(config('session.cookie'), $session)->withDevice($device)
            ->post('/impersonation/stop')
            ->assertRedirect('/users');
        $session = $this->sessionId($stop);

        $this->asNewRequest()->withCookie(config('session.cookie'), $session)->withDevice($device)->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($superAdmin);
    }

    public function test_an_old_laravel_remember_me_cookie_keeps_the_user_signed_in_and_is_replaced()
    {
        $user = User::factory()->withoutTwoFactor()->create(['remember_token' => 'legacy-token']);
        $legacy = $user->id . '|legacy-token|' . $user->password;
        $recaller = Auth::guard('web')->getRecallerName();

        $response = $this->asNewRequest()->withCookie($recaller, $legacy)->get('/dashboard')->assertOk();

        $this->assertAuthenticatedAs($user);
        $response->assertCookieExpired($recaller);
        $this->assertNotNull($this->deviceCookie($response));
        $this->assertSame(1, PersistentLogin::where('user_id', $user->id)->count());
    }

    public function test_logins_unused_for_the_idle_period_are_cleared_away()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $this->logIn($user, remember: true);
        $this->travel(5)->days();
        $this->asNewRequest()->logIn($user, remember: true);

        $this->travel(self::IDLE_DAYS - 2)->days();
        $this->artisan('model:prune', ['--model' => [PersistentLogin::class]]);

        $this->assertSame(1, PersistentLogin::count());
    }

    public function test_remember_me_runs_after_the_session_and_csrf_check_and_before_authentication()
    {
        $router = $this->app['router'];

        foreach (['dashboard' => 'web', 'logout' => 'web'] as $name => $group) {
            $order = array_map(fn ($middleware) => explode(':', $middleware)[0], $router->gatherRouteMiddleware($router->getRoutes()->getByName($name)));
            $at = fn (string $class) => array_search($class, $order, true);

            $this->assertNotFalse($at(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class), "{$name}: CSRF protection is still on");
            $this->assertLessThan($at(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class), $at(\Illuminate\Session\Middleware\StartSession::class));
            $this->assertLessThan($at(\App\Http\Middleware\RestorePersistentLogin::class), $at(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class));
            $this->assertLessThan($at(\Illuminate\Auth\Middleware\Authenticate::class), $at(\App\Http\Middleware\RestorePersistentLogin::class));
            $this->assertLessThan($at(\Illuminate\Session\Middleware\AuthenticateSession::class), $at(\App\Http\Middleware\RestorePersistentLogin::class));
        }

        $api = array_map(fn ($middleware) => explode(':', $middleware)[0], $router->gatherRouteMiddleware(
            collect($router->getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === 'api/user')
        ));
        $this->assertLessThan(
            array_search(\Illuminate\Auth\Middleware\Authenticate::class, $api, true),
            array_search(\App\Http\Middleware\RestorePersistentLogin::class, $api, true),
        );
    }

    private function logIn(User $user, bool $remember): TestResponse
    {
        $response = $this->post(route('login.store'), array_filter([
            'email' => $user->email,
            'password' => 'password',
            // What the login form's checkbox sends when it is ticked
            'remember' => $remember ? 'on' : null,
        ]));

        $response->assertRedirect('/dashboard');

        return $response;
    }

    /**
     * Forget who the test client was signed in as, so the next request is judged only on
     * the cookies it carries, like a browser coming back.
     */
    private function asNewRequest(): static
    {
        $this->app['auth']->forgetGuards();
        // The test client keeps the last request's session in memory; a browser only has its cookie
        $this->app['session']->driver()->flush();
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this;
    }

    private function withDevice(string $cookie): static
    {
        return $this->withCookie(PersistentLogins::cookieName(), $cookie);
    }

    /**
     * An API call from a page of the site, carrying only the "Remember me" cookie.
     */
    private function apiCall(string $device): TestResponse
    {
        return $this->asNewRequest()
            ->withCredentials()
            ->withHeader('Referer', 'http://localhost/dashboard')
            ->withDevice($device)
            ->getJson('/api/user');
    }

    /**
     * The "Remember me" cookie the response gives the browser, if any.
     */
    private function deviceCookie(TestResponse $response): ?string
    {
        $cookie = $response->getCookie(PersistentLogins::cookieName(), false);

        if (! $cookie || $cookie->getExpiresTime() <= time()) {
            return null;
        }

        return $response->getCookie(PersistentLogins::cookieName())->getValue();
    }

    private function sessionId(TestResponse $response): string
    {
        return $response->getCookie(config('session.cookie'))->getValue();
    }

    /**
     * Read config/session.php as it would be loaded with the given environment variables.
     *
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    private function sessionConfigWith(array $env): array
    {
        $names = ['APP_URL', 'SESSION_SECURE_COOKIE'];
        $saved = [];

        foreach ($names as $name) {
            $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }

        try {
            foreach ($env as $name => $value) {
                $_ENV[$name] = $_SERVER[$name] = $value;
                putenv("{$name}={$value}");
            }

            return require config_path('session.php');
        } finally {
            foreach ($saved as $name => [$env, $server, $getenv]) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);

                if ($env !== null) {
                    $_ENV[$name] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
                if ($getenv !== false) {
                    putenv("{$name}={$getenv}");
                }
            }
        }
    }
}
