<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PersistentLogins;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Turning phone / desktop alerts on and off for the browser the request comes from.
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // Only from the signed-in site itself, not from scripts using an access token
        abort_unless($request->hasSession(), 403);

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500', 'url:https', $this->pushServiceOnly()],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'in:aesgcm,aes128gcm'],
        ]);

        // On a shared browser the subscription moves to whoever is signed in now
        $subscription = $request->user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['contentEncoding'] ?? 'aesgcm',
        );

        // Alerts end with this browser's "Remember me" login
        $subscription->forceFill([
            'persistent_login_id' => $request->session()->get(PersistentLogins::SESSION_KEY),
        ]);
        $subscription->exists && ! $subscription->isDirty() ? $subscription->touch() : $subscription->save();

        return response()->json(['message' => 'Alerts are on for this device.'], $subscription->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);

        $request->user()->deletePushSubscription($validated['endpoint']);

        return response()->json(['message' => 'Alerts are off for this device.']);
    }

    private function pushServiceOnly(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));

            if ($host === '' || ! Str::is(config('webpush.allowed_hosts'), $host)) {
                $fail('This browser\'s push service is not supported.');
            }
        };
    }
}
