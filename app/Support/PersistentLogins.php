<?php

namespace App\Support;

use App\Models\PersistentLogin;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "Remember me": keeps someone signed in on a browser or installed app for as long as they
 * keep using it, without logging in again when the two-hour session ends.
 *
 * Each remembered browser has its own row and a cookie holding "selector|secret". Only a
 * hash of the secret is stored. The secret is replaced each time the browser is signed back
 * in and once a day, so a copied cookie stops working soon after the real browser carries on;
 * an old secret turning up after that ends the row (someone else has the cookie).
 *
 * A row ends when:
 *   - the browser is not used for the idle period (config auth.persistent_login.idle_days)
 *   - the user logs out on that browser
 *   - the password changes (every other browser) or the account is deactivated (all of them)
 */
class PersistentLogins
{
    /** Which row the current session was remembered with */
    public const SESSION_KEY = 'persistent_login_id';

    /** How often the secret is replaced while the browser stays signed in */
    private const ROTATE_AFTER_HOURS = 24;

    /** How long a new secret may go unseen before it is sent again (the reply carrying it was lost) */
    private const RESEND_AFTER_MINUTES = 5;

    /** How long the replaced secret is still accepted, for requests that were already on their way */
    private const PREVIOUS_GRACE_SECONDS = 120;

    /** How often the "last used" time is written while a session carries on */
    private const TOUCH_AFTER_MINUTES = 60;

    public static function cookieName(): string
    {
        return config('auth.persistent_login.cookie');
    }

    public static function idleMinutes(): int
    {
        return (int) config('auth.persistent_login.idle_days') * 24 * 60;
    }

    /**
     * Remember this browser for the user who has just logged in on it.
     */
    public static function start(Request $request, User $user): PersistentLogin
    {
        $selector = Str::random(32);
        $secret = Str::random(64);

        $login = PersistentLogin::create([
            'user_id' => $user->id,
            'selector' => $selector,
            'token_hash' => self::hash($secret),
            'token_issued_at' => now(),
            'last_used_at' => now(),
            'expires_at' => now()->addMinutes(self::idleMinutes()),
            'user_agent' => Str::limit((string) $request->userAgent(), 252),
            'ip_address' => $request->ip(),
        ]);

        $request->session()->put(self::SESSION_KEY, $login->id);
        self::queueCookie($selector, $secret);

        return $login;
    }

    /**
     * Sign a browser whose session has ended back in from its cookie.
     * Returns the user, or null when the cookie is no longer good.
     */
    public static function restore(Request $request): ?User
    {
        [$login, $secret] = self::fromCookie($request);

        if (! $login) {
            self::forgetCookie();

            return null;
        }

        $match = self::match($login, $secret);

        if ($match === null) {
            // The selector is right but the secret is an old one: the cookie has been copied
            // and used elsewhere. End this browser's login so neither copy works any more.
            Log::warning('Persistent login ended: an outdated secret was presented', [
                'user_id' => $login->user_id,
                'persistent_login_id' => $login->id,
                'ip' => $request->ip(),
            ]);
            $login->delete();
            self::forgetCookie();

            return null;
        }

        $user = $login->user;

        if ($login->isExpired() || ! $user || ! $user->isActive()) {
            $login->delete();
            self::forgetCookie();

            return null;
        }

        // The same login carrying on, not a new one, so no "Logged in" event or audit entry.
        // A new session id, as on any login.
        $guard = Auth::guard('web');
        $request->session()->put($guard->getName(), $user->getAuthIdentifier());
        $request->session()->put(self::SESSION_KEY, $login->id);
        $request->session()->migrate(true);
        $guard->setUser($user);

        self::used($login, $match, $secret, rotate: true);

        return $user;
    }

    /**
     * For a session that was remembered: whether its login still stands (it has not been ended
     * by a logout elsewhere, a password change or deactivation), keeping it fresh while used.
     */
    public static function check(Request $request): bool
    {
        $login = PersistentLogin::find($request->session()->get(self::SESSION_KEY));
        $owner = Impersonation::impersonatorId() ?? Auth::guard('web')->id();

        if (! $login || $login->isExpired() || $login->user_id !== $owner) {
            return false;
        }

        [$fromCookie, $secret] = self::fromCookie($request);
        $match = $fromCookie?->is($login) ? self::match($login, $secret) : null;

        if ($match === 'pending' || $login->last_used_at->lt(now()->subMinutes(self::TOUCH_AFTER_MINUTES))) {
            self::used($login, $match, $secret, rotate: false);
        }

        return true;
    }

    /**
     * Logging out: forget this browser.
     */
    public static function end(Request $request): void
    {
        $id = $request->hasSession() ? $request->session()->pull(self::SESSION_KEY) : null;
        [$fromCookie] = self::fromCookie($request);

        PersistentLogin::whereKey(array_filter([$id, $fromCookie?->id]))->delete();
        self::forgetCookie();
    }

    /**
     * End the user's remembered logins, all of them or all but one.
     */
    public static function revokeAll(User $user, ?int $except = null): void
    {
        PersistentLogin::where('user_id', $user->id)
            ->when($except, fn ($query) => $query->whereKeyNot($except))
            ->delete();
    }

    /**
     * The row the current request is signed in with, when it belongs to the given user.
     */
    public static function currentFor(User $user): ?int
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        $request = request();

        if (! $request->hasSession() || Impersonation::isActive() || Auth::guard('web')->id() !== $user->id) {
            return null;
        }

        return $request->session()->get(self::SESSION_KEY);
    }

    public static function forgetCookie(): void
    {
        if (request()->cookies->has(self::cookieName())) {
            Cookie::queue(Cookie::forget(self::cookieName()));
        }
    }

    /**
     * Record a use: push the expiry back, keep the cookie as long, and replace the secret
     * when it is due.
     *
     * @param  'current'|'pending'|'previous'|null  $match  Which secret the cookie carried
     */
    private static function used(PersistentLogin $login, ?string $match, ?string $secret, bool $rotate): void
    {
        $now = now();

        if ($match === 'pending') {
            // The browser has the new secret, so the one before it is done with
            $login->fill([
                'previous_token_hash' => $login->token_hash,
                'previous_valid_until' => $now->copy()->addSeconds(self::PREVIOUS_GRACE_SECONDS),
                'token_hash' => $login->pending_token_hash,
                'token_issued_at' => $login->pending_issued_at,
                'pending_token_hash' => null,
                'pending_issued_at' => null,
            ]);
            $match = 'current';
        }

        $newSecretOnItsWay = $login->pending_issued_at?->gt($now->copy()->subMinutes(self::RESEND_AFTER_MINUTES));
        $cookie = $match === 'current' ? $secret : null;

        if ($match === 'current' && ! $newSecretOnItsWay) {
            $due = $rotate
                || $login->pending_issued_at !== null // sent before, but the browser never got it
                || $login->token_issued_at->lt($now->copy()->subHours(self::ROTATE_AFTER_HOURS));

            if ($due) {
                $cookie = Str::random(64);
                $login->fill(['pending_token_hash' => self::hash($cookie), 'pending_issued_at' => $now]);
            }
        } elseif ($newSecretOnItsWay) {
            // Another request already sent the browser a new secret; don't overwrite it
            $cookie = null;
        }

        $login->fill([
            'last_used_at' => $now,
            'expires_at' => $now->copy()->addMinutes(self::idleMinutes()),
        ])->save();

        if ($cookie !== null) {
            self::queueCookie($login->selector, $cookie);
        }
    }

    /**
     * @return 'current'|'pending'|'previous'|null
     */
    private static function match(PersistentLogin $login, ?string $secret): ?string
    {
        if ($secret === null) {
            return null;
        }

        $hash = self::hash($secret);

        return match (true) {
            hash_equals($login->token_hash, $hash) => 'current',
            $login->pending_token_hash !== null && hash_equals($login->pending_token_hash, $hash) => 'pending',
            $login->previous_token_hash !== null
                && $login->previous_valid_until?->isFuture()
                && hash_equals($login->previous_token_hash, $hash) => 'previous',
            default => null,
        };
    }

    /**
     * @return array{0: ?PersistentLogin, 1: ?string}
     */
    private static function fromCookie(Request $request): array
    {
        $value = $request->cookies->get(self::cookieName());

        if (! is_string($value) || ! preg_match('/^([A-Za-z0-9]{32})\|([A-Za-z0-9]{64})$/', $value, $parts)) {
            return [null, null];
        }

        return [PersistentLogin::where('selector', $parts[1])->first(), $parts[2]];
    }

    private static function queueCookie(string $selector, string $secret): void
    {
        Cookie::queue(Cookie::make(self::cookieName(), $selector . '|' . $secret, self::idleMinutes()));
    }

    private static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
