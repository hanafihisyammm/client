/**
 * Phone / desktop alerts end to end in a real browser: turning them on from the notifications
 * menu, the server sending one through the browser's real push service, the browser showing
 * it, and turning alerts off and logging out removing the device.
 *
 * Headless Chrome keeps no connection to Google's push service, so it cannot receive what the
 * service sends on. The test checks the two halves around that hop: Google's push service
 * accepts the server's real, encrypted alert for this browser, and the same alert handed to the
 * browser's own service worker (through DevTools) is shown.
 *
 * Needs the local site running from a production build with the demo data and VAPID keys
 * (php artisan webpush:vapid), and an internet connection for Google's push service:
 *   node --test tests/browser/push.test.mjs
 * The alert is sent through the push channel only.
 */
/* global process */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { after, before, test } from 'node:test';
import { launch, sleep } from './lib.mjs';

const BASE = process.env.APP_URL || 'http://127.0.0.1:8000';
const EMAIL = 'acme.staff1@example.com';

let page;

const text = () => page.evaluate('document.body.innerText');
const subscription = () => page.evaluate(`navigator.serviceWorker.ready
    .then((worker) => worker.pushManager.getSubscription())
    .then((subscription) => subscription && subscription.endpoint)`);
const shownAlerts = () => page.evaluate(`navigator.serviceWorker.ready
    .then((worker) => worker.getNotifications())
    .then((list) => list.map((alert) => ({ title: alert.title, body: alert.body, url: alert.data && alert.data.url })))`);

/** Run PHP in the app, as the server would. */
const php = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], { encoding: 'utf8' }).trim();
const savedDevices = () => Number(php(`echo App\\Models\\User::where('email', '${EMAIL}')->first()->pushSubscriptions()->count();`));

const ALERT = `new class extends App\\Notifications\\AppNotification {
    public function toArray($notifiable) { return ['title' => 'Browser test alert', 'message' => 'Sent by tests/browser/push.test.mjs', 'url' => '/settings/profile']; }
}`;
const USER = `App\\Models\\User::where('email', '${EMAIL}')->first()`;

/** Hand an alert to the page's service worker, as the browser does when one arrives. */
async function deliverToServiceWorker(data) {
    const targets = await fetch(`http://127.0.0.1:${page.port}/json`).then((response) => response.json());
    const socket = new WebSocket(targets.find((target) => target.type === 'page' && target.url.startsWith(BASE)).webSocketDebuggerUrl);
    await new Promise((resolve) => (socket.onopen = resolve));

    const registration = new Promise((resolve) => {
        socket.onmessage = ({ data: message }) => {
            const { method, params } = JSON.parse(message);
            const found = method === 'ServiceWorker.workerRegistrationUpdated' && params.registrations.find((item) => item.scopeURL === `${BASE}/` && !item.isDeleted);
            if (found) resolve(found.registrationId);
        };
    });
    socket.send(JSON.stringify({ id: 1, method: 'ServiceWorker.enable' }));
    const registrationId = await registration;
    socket.send(JSON.stringify({ id: 2, method: 'ServiceWorker.deliverPushMessage', params: { origin: BASE, registrationId, data } }));
    await sleep(500);
    socket.close();
}

/** Ask until the answer is right (or time runs out): registering with a push service takes a while. */
async function waitFor(check, seconds = 20) {
    for (let waited = 0; waited < seconds * 2; waited++) {
        const value = await check();
        if (value) return value;
        await sleep(500);
    }
    return check();
}

const openMenu = async () => {
    await page.press('Escape');
    assert.ok(await page.click('button[aria-label^="Notifications"]', null, 1200), 'the notifications menu');
};

before(async () => {
    page = await launch();
    await page.send('Browser.grantPermissions', { origin: BASE, permissions: ['notifications'] });
    await page.login(BASE, EMAIL, 'password');
    php(`App\\Models\\User::where('email', '${EMAIL}')->first()->pushSubscriptions()->delete();`);
});

after(async () => {
    await page?.close();
});

test('alerts can be turned on from the notifications menu', async () => {
    await openMenu();
    assert.match(await text(), /Get an alert on this device/);

    assert.ok(await page.click('[role="menu"] button', 'Turn on', 500));

    assert.match(await waitFor(subscription), /^https:\/\/fcm\.googleapis\.com\//);
    assert.ok(await waitFor(() => savedDevices() === 1), 'the server has the device');
    assert.match(await waitFor(async () => (/Alerts are on for this device/.test(await text()) ? await text() : null), 5), /Alerts are on for this device/);
});

test("Google's push service accepts the server's alert for this browser", async () => {
    const answers = php(`
        $alert = ${ALERT};
        $alert->id = (string) Illuminate\\Support\\Str::uuid();
        foreach (app(NotificationChannels\\WebPush\\WebPushChannel::class)->send(${USER}, $alert) as $report) {
            echo $report->getResponse()?->getStatusCode() . ' ' . parse_url($report->getEndpoint(), PHP_URL_HOST) . PHP_EOL;
        }
    `);

    assert.equal(answers, '201 fcm.googleapis.com');
});

test('the alert the server sends is shown by the browser', async () => {
    const payload = php(`$alert = ${ALERT}; $alert->id = 'browser-test'; echo json_encode($alert->toWebPush(${USER}, $alert)->toArray());`);
    let alerts = [];
    for (let attempt = 0; attempt < 3 && alerts.length === 0; attempt++) {
        await deliverToServiceWorker(payload);
        alerts = await waitFor(async () => ((await shownAlerts()).length ? shownAlerts() : null), 5) ?? [];
    }

    // The real one sent through Google in the step before sometimes arrives too, so one or two
    assert.ok(alerts.length >= 1, 'the alert is shown');
    for (const alert of alerts) {
        assert.deepEqual(alert, { title: 'Browser test alert', body: 'Sent by tests/browser/push.test.mjs', url: '/settings/profile' });
    }
});

test('alerts can be turned off again', async () => {
    await openMenu();
    assert.ok(await page.click('[role="menu"] button', 'Turn off', 500));

    assert.equal(await waitFor(async () => (await subscription()) === null), true);
    assert.ok(await waitFor(() => savedDevices() === 0), 'the server forgot the device');
});

test('logging out stops this browser\'s alerts', async () => {
    await openMenu();
    assert.ok(await page.click('[role="menu"] button', 'Turn on', 500));
    assert.ok(await waitFor(() => savedDevices() === 1), 'the server has the device');
    await page.press('Escape');

    assert.ok(await page.click('[data-test="sidebar-menu-button"]', null, 700));
    assert.ok(await page.click('[data-test="logout-button"]', null, 2500));

    assert.equal(await page.evaluate('location.pathname'), '/');
    assert.equal(await subscription(), null, 'the browser unsubscribed');
    assert.equal(savedDevices(), 0, 'the server forgot the device');
});
