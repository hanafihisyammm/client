<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page shown after logging in with a password an admin gave you. Your current password is
 * not asked for: changing it ends every older session, so this session began with it moments ago.
 */
class ChangeTemporaryPasswordController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (!$user->must_change_password) {
            return redirect('/dashboard');
        }

        return Inertia::render('auth/change-temporary-password', [
            'email' => $user->email,
            'name' => $user->name,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (!$user->must_change_password) {
            return redirect('/dashboard');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if (Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Please choose a password different from the one you were given.',
            ]);
        }

        // A new password also ends "Remember me" on every other browser (see AppServiceProvider)
        $user->forceFill([
            'name' => $validated['name'],
            'password' => $validated['password'],
            'remember_token' => Str::random(60),
            'must_change_password' => false,
            // Choosing their own password is what finishes setting up a new account
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        // Other sessions end once their saved password no longer matches, so keep this one's in step
        if ($request->session()->has('password_hash_web')) {
            $request->session()->put('password_hash_web', $user->getAuthPassword());
        }

        return redirect('/dashboard')->with('flash', [
            'message' => 'Your new password is saved. Welcome to ' . config('app.name') . '!',
            'type' => 'success',
        ]);
    }
}
