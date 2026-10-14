<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /**
     * Show the user's password settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/password');
    }

    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user = $request->user();

        // A new password also ends "Remember me" on every other browser (see AppServiceProvider),
        // including any of Laravel's own older "Remember me" cookies; this one stays signed in
        $user->forceFill([
            'password' => $validated['password'],
            'remember_token' => Str::random(60),
        ])->save();

        // Other sessions end once their saved password no longer matches, so keep this one's in step
        if ($request->session()->has('password_hash_web')) {
            $request->session()->put('password_hash_web', $user->getAuthPassword());
        }

        return back()->with('flash', [
            'message' => 'Password updated successfully.',
            'type' => 'success',
        ]);
    }
}
