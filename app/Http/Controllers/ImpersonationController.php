<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Sign in as": lets a Super Admin use the app exactly as another user would,
 * with that user's role, company and project access, without knowing their password.
 */
class ImpersonationController extends Controller
{
    use AuthorizesRequests;

    /**
     * Start using the app as the given user.
     */
    public function start(Request $request, User $user): RedirectResponse
    {
        // Already signed in as someone: go back to the Super Admin before choosing another person
        if (Impersonation::isActive()) {
            abort(403, 'Return to Super Admin before signing in as another user.');
        }

        $this->authorize('impersonate', $user);

        $superAdmin = $request->user();

        AuditLog::record(
            'auth.impersonation_started',
            "Signed in as {$user->name} ({$user->email})",
            'user',
            $user->id,
            $user->email,
            null,
            $superAdmin
        );

        // Remember who is really behind this session, then switch the session to the other user.
        // No "remember me": nothing is written to the user being signed in as.
        $request->session()->put(Impersonation::SESSION_KEY, $superAdmin->id);
        $this->switchSessionTo($request, $user);

        return redirect()->route('dashboard')->with('flash', [
            'message' => "You are now signed in as {$user->name}.",
            'type' => 'success',
        ]);
    }

    /**
     * Go back to the Super Admin's own account.
     */
    public function stop(Request $request): RedirectResponse
    {
        $superAdmin = Impersonation::impersonator();

        if (Impersonation::impersonatorId() === null) {
            abort(403, 'You are not signed in as another user.');
        }

        $impersonated = $request->user();

        // The Super Admin may have been removed, demoted or deactivated in the meantime:
        // never hand the session to an account that should no longer have that access
        if (!$superAdmin || $superAdmin->role !== 'super_admin' || !$superAdmin->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Your Super Admin session could not be restored. Please log in again.');
        }

        AuditLog::record(
            'auth.impersonation_ended',
            "Stopped being signed in as {$impersonated->name} ({$impersonated->email})",
            'user',
            $impersonated->id,
            $impersonated->email,
            null,
            $superAdmin
        );

        // Switch the session back, then drop the marker. It is still set during the switch
        // so that this is not also recorded as an ordinary login.
        $this->switchSessionTo($request, $superAdmin);
        $request->session()->forget(Impersonation::SESSION_KEY);

        return redirect()->route('users.index')->with('flash', [
            'message' => 'You are back in your Super Admin account.',
            'type' => 'success',
        ]);
    }

    /**
     * Make the session belong to the given user.
     */
    private function switchSessionTo(Request $request, User $user): void
    {
        // The API remembers the password hash of whoever the session belonged to and ends the
        // session when it no longer matches (that is how changing a password logs other devices
        // out). After a switch it would compare the old user's hash with the new user and log
        // them out, so drop it here; it is stored again for the new user on their next request.
        foreach (array_keys(config('auth.guards')) as $guard) {
            $request->session()->forget('password_hash_' . $guard);
        }

        Auth::guard('web')->login($user);
    }
}
