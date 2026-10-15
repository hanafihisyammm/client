<?php

namespace Tests\Feature;

use App\Models\PersistentLogin;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\AppNotification;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Concerns\BuildsCompanies;
use Tests\TestCase;

/**
 * Phone / desktop alerts (Web Push): turning them on and off, who receives them, that they
 * stop with the browser's login, and what is actually sent to the browsers' push services.
 * What the service worker does with an alert is tested in tests/browser/service-worker.test.mjs.
 */
class PushNotificationTest extends TestCase
{
    use BuildsCompanies;
    use RefreshDatabase;

    private const CHROME = 'https://fcm.googleapis.com/fcm/send/abc123';
    private const FIREFOX = 'https://updates.push.services.mozilla.com/wpush/v2/abc123';
    private const EDGE_WINDOWS = 'https://wns2-bl2p.notify.windows.com/w/?token=abc123';
    private const SAFARI = 'https://web.push.apple.com/QGuQyavXutnMH-abc123';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $sent = [];

    private MockHandler $pushService;

    protected function setUp(): void
    {
        parent::setUp();

        $keys = VAPID::createVapidKeys();
        config([
            'webpush.vapid.subject' => 'https://desk.example.com',
            'webpush.vapid.public_key' => $keys['publicKey'],
            'webpush.vapid.private_key' => $keys['privateKey'],
        ]);

        // The real Web Push client (encryption, VAPID signature), with the push services faked
        $this->pushService = new MockHandler;
        $stack = HandlerStack::create($this->pushService);
        $stack->push(Middleware::history($this->sent));

        $this->app->when(WebPushChannel::class)->needs(WebPush::class)->give(fn () => new WebPush(
            ['VAPID' => [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ]],
            [],
            new Client(['handler' => $stack]),
            new HttpFactory,
            new HttpFactory,
        ));
    }

    public function test_alerts_can_be_turned_on_from_every_supported_browser()
    {
        $user = User::factory()->withoutTwoFactor()->create();

        foreach ([self::CHROME, self::FIREFOX, self::EDGE_WINDOWS, self::SAFARI] as $endpoint) {
            $this->actingAs($user)->subscribe($endpoint)->assertCreated();
        }

        $this->assertSame(4, $user->pushSubscriptions()->count());
    }

    public function test_only_the_browsers_push_services_are_accepted()
    {
        $user = User::factory()->withoutTwoFactor()->create();

        $refused = [
            'http://fcm.googleapis.com/fcm/send/abc',           // not https
            'https://example.com/push',                          // someone else's server
            'https://169.254.169.254/latest/meta-data',          // the server's own network
            'https://127.0.0.1/internal',
            'https://fcm.googleapis.com.evil.example/fcm/send',  // looks alike
            'https://evil.example/?fcm.googleapis.com',
            'javascript:alert(1)',
            'not a url',
        ];

        foreach ($refused as $endpoint) {
            $this->actingAs($user)->subscribe($endpoint)->assertUnprocessable()->assertJsonValidationErrors('endpoint');
        }

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_the_keys_are_required()
    {
        $user = User::factory()->withoutTwoFactor()->create();

        $this->actingAs($user)
            ->withCredentials()->withHeader('Referer', 'http://localhost/dashboard')
            ->postJson('/api/push-subscriptions', ['endpoint' => self::CHROME])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);
    }

    public function test_a_script_with_an_access_token_cannot_register_a_device()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $token = $user->createToken('script')->plainTextToken;

        $this->withToken($token)->postJson('/api/push-subscriptions', $this->subscription(self::CHROME))->assertForbidden();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_a_super_admin_signed_in_as_someone_does_not_get_their_alerts()
    {
        $superAdmin = $this->makeAdmin('super_admin');
        [, $manager] = $this->makeCompany('Acme');

        $this->actingAs($superAdmin)->post('/users/' . $manager->uuid . '/impersonate');
        $this->subscribe(self::CHROME)->assertForbidden();

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_a_shared_browser_alerts_whoever_is_signed_in_now()
    {
        $first = User::factory()->withoutTwoFactor()->create();
        $second = User::factory()->withoutTwoFactor()->create();

        $this->actingAs($first)->subscribe(self::CHROME)->assertCreated();
        $this->actingAs($second)->subscribe(self::CHROME)->assertCreated();

        $this->assertSame(0, $first->pushSubscriptions()->count());
        $this->assertSame(1, $second->pushSubscriptions()->count());
    }

    public function test_turning_alerts_off_removes_only_your_own_device()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $other = User::factory()->withoutTwoFactor()->create();
        $other->updatePushSubscription(self::FIREFOX, 'key', 'auth');
        $this->actingAs($user)->subscribe(self::CHROME);

        $this->actingAs($user)->deleteJson('/api/push-subscriptions', ['endpoint' => self::FIREFOX])->assertOk();
        $this->actingAs($user)->deleteJson('/api/push-subscriptions', ['endpoint' => self::CHROME])->assertOk();

        $this->assertSame(0, $user->pushSubscriptions()->count());
        $this->assertSame(1, $other->pushSubscriptions()->count(), "someone else's device is untouched");
    }

    public function test_a_remembered_browsers_alerts_are_tied_to_its_login()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $this->rememberedLogin($user);

        $this->subscribe(self::CHROME)->assertCreated();

        $this->assertSame(PersistentLogin::sole()->id, PushSubscription::sole()->persistent_login_id);
    }

    public function test_logging_out_stops_this_browsers_alerts()
    {
        // Remembered: its alerts end with its login, whatever the page sends
        $remembered = User::factory()->withoutTwoFactor()->create();
        $this->rememberedLogin($remembered);
        $this->subscribe(self::CHROME);
        $this->post('/logout')->assertRedirect('/');
        $this->assertSame(0, $remembered->pushSubscriptions()->count());

        // Not remembered: the page sends this browser's alert address with the logout
        $sessionOnly = User::factory()->withoutTwoFactor()->create();
        $this->actingAs($sessionOnly)->subscribe(self::FIREFOX);
        $this->actingAs($sessionOnly)->post('/logout', ['push_endpoint' => self::FIREFOX])->assertRedirect('/');
        $this->assertSame(0, $sessionOnly->pushSubscriptions()->count());
    }

    public function test_changing_the_password_stops_alerts_everywhere_but_this_browser()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $this->rememberedLogin($user);
        $this->subscribe(self::CHROME);
        // Two other devices of the same user
        $user->updatePushSubscription(self::FIREFOX, 'key', 'auth');
        $otherLogin = PersistentLogin::create([
            'user_id' => $user->id, 'selector' => str_repeat('s', 32), 'token_hash' => str_repeat('0', 64),
            'token_issued_at' => now(), 'last_used_at' => now(), 'expires_at' => now()->addDays(90),
        ]);
        $user->updatePushSubscription(self::SAFARI, 'key', 'auth')->forceFill(['persistent_login_id' => $otherLogin->id])->save();

        $this->from('/settings/password')->put('/settings/password', [
            'current_password' => 'password',
            'password' => 'a-new-Password-123',
            'password_confirmation' => 'a-new-Password-123',
        ])->assertSessionHasNoErrors();

        $this->assertSame([self::CHROME], $user->pushSubscriptions()->pluck('endpoint')->all());
    }

    public function test_a_deactivated_account_stops_getting_alerts_everywhere()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $user->updatePushSubscription(self::CHROME, 'key', 'auth');
        $user->updatePushSubscription(self::SAFARI, 'key', 'auth');

        $user->update(['deactivated_at' => now()]);

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_alerts_end_when_the_browsers_remembered_login_is_cleared_away()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $this->rememberedLogin($user);
        $this->subscribe(self::CHROME);

        $this->travel(91)->days();
        $this->artisan('model:prune', ['--model' => [PersistentLogin::class, PushSubscription::class]]);

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_alerts_of_a_browser_that_stopped_opening_the_app_are_cleared_away()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $this->actingAs($user)->subscribe(self::CHROME);
        $this->travel(60)->days();
        // Opening the app re-registers the device, which keeps it
        $this->actingAs($user)->subscribe(self::FIREFOX);

        $this->travel(31)->days();
        $this->artisan('model:prune', ['--model' => [PushSubscription::class]]);

        $this->assertSame([self::FIREFOX], PushSubscription::pluck('endpoint')->all());
    }

    public function test_notifications_go_out_as_alerts_only_to_users_who_turned_them_on()
    {
        $withAlerts = User::factory()->withoutTwoFactor()->create();
        $withoutAlerts = User::factory()->withoutTwoFactor()->create();
        $withAlerts->updatePushSubscription(self::CHROME, ...$this->browserKeys());

        $this->assertContains(WebPushChannel::class, (new TestAlert)->via($withAlerts));
        $this->assertNotContains(WebPushChannel::class, (new TestAlert)->via($withoutAlerts));
        $this->assertSame(['database'], (new TestAlert)->via($withoutAlerts));
    }

    public function test_the_alert_carries_the_notifications_title_message_and_page()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $notification = new TestAlert;
        $notification->id = 'abc-123';

        $alert = $notification->toWebPush($user, $notification)->toArray();

        $this->assertSame('New comment: Checkout button', $alert['title']);
        $this->assertSame('Ben: It still fails on Safari', $alert['body']);
        $this->assertSame('/reports/0b6e', $alert['data']['url']);
        $this->assertSame('abc-123', $alert['tag']);
        $this->assertSame('/icons/icon-192.png', $alert['icon']);

        // A link to anywhere but this site is never passed on
        foreach (['https://evil.example/x', '//evil.example/x', 'javascript:alert(1)'] as $url) {
            $this->assertSame('/dashboard', (new TestAlert($url))->toWebPush($user, $notification)->toArray()['data']['url']);
        }
    }

    public function test_an_alert_is_sent_encrypted_and_signed_to_every_device_of_the_user()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $user->updatePushSubscription(self::CHROME, ...$this->browserKeys());
        $user->updatePushSubscription(self::SAFARI, ...$this->browserKeys());
        $this->pushService->append(new Response(201), new Response(201));

        $user->notify(new TestAlert);

        $this->assertCount(2, $this->sent);
        $this->assertEqualsCanonicalizing([self::CHROME, self::SAFARI], array_map(fn ($sent) => (string) $sent['request']->getUri(), $this->sent));

        foreach ($this->sent as ['request' => $request]) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
            $this->assertSame('86400', $request->getHeaderLine('TTL'));
            $this->assertStringStartsWith('vapid t=', $request->getHeaderLine('Authorization'));
            // Only the browser can read it: the push service sees nothing of the content
            $body = (string) $request->getBody();
            $this->assertStringNotContainsString('Checkout button', $body);
            $this->assertStringNotContainsString('/reports/', $body);
        }

        // The in-app notification is still there as before
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_a_device_the_push_service_no_longer_knows_is_forgotten()
    {
        $user = User::factory()->withoutTwoFactor()->create();
        $user->updatePushSubscription(self::CHROME, ...$this->browserKeys());
        $user->updatePushSubscription(self::FIREFOX, ...$this->browserKeys());
        // The browser unsubscribed or was uninstalled: the service answers "gone" for it
        $this->pushService->append(fn ($request) => new Response(str_contains((string) $request->getUri(), 'fcm') ? 410 : 201));
        $this->pushService->append(fn ($request) => new Response(str_contains((string) $request->getUri(), 'fcm') ? 410 : 201));

        $user->notify(new TestAlert);

        $this->assertSame([self::FIREFOX], $user->pushSubscriptions()->pluck('endpoint')->all());
    }

    public function test_the_public_key_is_given_to_signed_in_pages_only()
    {
        $user = User::factory()->withoutTwoFactor()->create();

        $this->get('/login')->assertInertia(fn ($page) => $page->where('webPush', null));
        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('webPush.publicKey', config('webpush.vapid.public_key')));
    }

    private function subscribe(string $endpoint): TestResponse
    {
        return $this->withCredentials()
            ->withHeader('Referer', 'http://localhost/dashboard')
            ->postJson('/api/push-subscriptions', $this->subscription($endpoint));
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(string $endpoint): array
    {
        [$key, $auth] = $this->browserKeys();

        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => $key, 'auth' => $auth], 'contentEncoding' => 'aes128gcm'];
    }

    /**
     * Keys like a browser creates for its subscription: its public P-256 key and a secret.
     *
     * @return array{0: string, 1: string}
     */
    private function browserKeys(): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]));
        $encode = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        // OpenSSL drops leading zero bytes; a P-256 point is always 32 + 32 bytes
        $coordinate = fn (string $bytes) => str_pad($bytes, 32, "\0", STR_PAD_LEFT);

        return [$encode("\x04" . $coordinate($details['ec']['x']) . $coordinate($details['ec']['y'])), $encode(random_bytes(16))];
    }

    private function rememberedLogin(User $user): void
    {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => 'on'])->assertRedirect('/dashboard');
    }
}

class TestAlert extends AppNotification
{
    public function __construct(private string $url = '/reports/0b6e')
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'New comment: Checkout button',
            'message' => 'Ben: It still fails on Safari',
            'url' => $this->url,
        ];
    }
}
