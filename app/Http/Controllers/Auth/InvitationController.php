<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    /**
     * Show the page where an invited user sets their name and password.
     */
    public function show(Request $request, User $user): Response|RedirectResponse
    {
        if ($redirect = $this->redirectIfAlreadySetUp($user)) {
            return $redirect;
        }

        return Inertia::render('auth/accept-invitation', [
            'email' => $user->email,
            'name' => $user->name,
            // Posting back to the same signed address keeps the link's signature on the form
            'submitUrl' => $request->getRequestUri(),
        ]);
    }

    /**
     * Save the invited user's name and password, then log them in.
     */
    public function store(Request $request, User $user): RedirectResponse
    {
        if ($redirect = $this->redirectIfAlreadySetUp($user)) {
            return $redirect;
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user->forceFill([
            'name' => $validated['name'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/dashboard')->with('flash', [
            'message' => 'Your account is set up. Welcome to ' . config('app.name') . '!',
            'type' => 'success',
        ]);
    }

    /**
     * An invitation can only be used once; afterwards the user logs in normally.
     */
    private function redirectIfAlreadySetUp(User $user): ?RedirectResponse
    {
        if ($user->email_verified_at === null) {
            return null;
        }

        return redirect()->route('login')
            ->with('status', 'Your account is already set up. Please log in.');
    }
}
