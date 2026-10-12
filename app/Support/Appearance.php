<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The colour theme: "light", "dark", or "system" (follow the computer's own setting).
 *
 * A logged-in user's choice is saved on their account, so it follows them to any browser.
 * It is also kept in a cookie, so the same browser keeps the theme on the login page
 * and the page can be drawn in the right colours from the very first byte.
 */
class Appearance
{
    public const OPTIONS = ['light', 'dark', 'system'];
    public const DEFAULT = 'system';
    public const COOKIE = 'appearance';

    /** One year, in minutes */
    private const COOKIE_LIFETIME = 60 * 24 * 365;

    /**
     * The theme to use for this request: the user's saved choice, else this browser's, else "system".
     */
    public static function for(Request $request): string
    {
        return self::valid($request->user()?->appearance)
            ?? self::valid($request->cookie(self::COOKIE))
            ?? self::DEFAULT;
    }

    /**
     * Remember the theme in this browser. Readable by the page's own script, which applies it.
     */
    public static function remember(string $appearance): void
    {
        cookie()->queue(cookie(self::COOKIE, $appearance, self::COOKIE_LIFETIME, httpOnly: false, sameSite: 'lax'));
    }

    private static function valid(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::OPTIONS, true) ? $value : null;
    }
}
