/**
 * The installable app in a real browser: installability, staying signed in across reloads
 * and a browser restart, logging out, what is (and is not) stored for offline use, how being
 * offline is shown, and the layout on a phone and a desktop.
 *
 * Needs the local site running from a production build (npm run build, no public/hot) with
 * the demo data:
 *   node --test tests/browser/pwa.test.mjs
 * Screenshots go to storage/app/pwa-check.
 */
/* global process */
import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after, before, test } from 'node:test';
import { launch, sleep } from './lib.mjs';

const BASE = process.env.APP_URL || 'http://127.0.0.1:8000';
const EMAIL = 'acme.staff1@example.com';
const DEACTIVATED = 'acme.staff3@example.com';
const SESSION_COOKIE = 'sidra-desk-session';
const SHOTS = 'storage/app/pwa-check';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

const profile = mkdtempSync(join(tmpdir(), 'sidra-pwa-'));
let page;

const path = () => page.evaluate('location.pathname');
const text = () => page.evaluate('document.body.innerText');
const cookies = async () => (await page.send('Network.getCookies', { urls: [BASE] })).cookies;
const rememberCookie = async () => (await cookies()).find((cookie) => cookie.name === 'remember_device');
const waitForServiceWorker = () => page.evaluate(`Promise.race([
    navigator.serviceWorker.ready.then(() => true),
    new Promise((resolve) => setTimeout(() => resolve(false), 8000)),
])`);
const conditions = (offline) => ({ offline, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
let workerSocket;

/**
 * Cut the connection. The service worker fetches on its own, outside the page, so it has to be
 * taken offline too; otherwise it would still reach the server.
 */
async function offline(on) {
    await page.send('Network.emulateNetworkConditions', conditions(on));

    if (!workerSocket) {
        const targets = await fetch(`http://127.0.0.1:${page.port}/json`).then((response) => response.json());
        const worker = targets.find((target) => target.type === 'service_worker');
        assert.ok(worker, 'the service worker is running');
        workerSocket = new WebSocket(worker.webSocketDebuggerUrl);
        await new Promise((resolve, reject) => {
            workerSocket.onopen = resolve;
            workerSocket.onerror = reject;
        });
        workerSocket.send(JSON.stringify({ id: 1, method: 'Network.enable' }));
    }
    workerSocket.send(JSON.stringify({ id: 2, method: 'Network.emulateNetworkConditions', params: conditions(on) }));
    await sleep(300);
}

/** Every address kept in the service worker's caches. */
const storedPaths = () => page.evaluate(`(async () => {
    const paths = [];
    for (const name of await caches.keys()) {
        const cache = await caches.open(name);
        for (const request of await cache.keys()) paths.push(new URL(request.url).pathname + new URL(request.url).search);
    }
    return paths;
})()`);

/**
 * Anything that sticks out past the right edge of the screen: it would either make the page
 * scroll sideways or, where the layout hides the overflow, be cut off. Areas that are meant to
 * scroll sideways (the issue board, wide tables) are fine.
 */
const cutOff = () => page.evaluate(`(() => {
    const width = document.documentElement.clientWidth;
    const insideScrollingArea = (el) => {
        for (let node = el.parentElement; node; node = node.parentElement) {
            if (/(auto|scroll)/.test(getComputedStyle(node).overflowX) && node.scrollWidth > node.clientWidth) return true;
        }
        return false;
    };
    const found = [];
    for (const el of document.querySelectorAll('body *')) {
        const box = el.getBoundingClientRect();
        if (!box.width || !box.height || box.right <= width + 1) continue;
        if (el.closest('[data-sidebar], [role="dialog"], [aria-hidden="true"]') || insideScrollingArea(el)) continue;
        // Only the innermost element, so each problem is listed once
        if ([...el.children].some((child) => child.getBoundingClientRect().right > width + 1)) continue;
        found.push((el.textContent || el.tagName).trim().slice(0, 40));
    }
    if (document.documentElement.scrollWidth > width) found.push('the page scrolls sideways');
    return found;
})()`);

/** One of this user's issues, to open its page. */
const anIssue = () => page.evaluate(`fetch('/api/reports', { headers: { Accept: 'application/json' } })
    .then((r) => r.json())
    .then((body) => { const list = body.data ?? body; return list[0] ? '/reports/' + list[0].uuid : null; })
    .catch(() => null)`);

before(async () => {
    mkdirSync(SHOTS, { recursive: true });
    page = await launch({ profile });
});

after(async () => {
    await page?.close();
    rmSync(profile, { recursive: true, force: true });
});

test('the site is installable: manifest, icons and service worker', async () => {
    await page.goto(`${BASE}/login`, 2000);
    assert.ok(await waitForServiceWorker(), 'the service worker is ready (is this a production build?)');

    const { errors, url } = await page.send('Page.getAppManifest');
    assert.equal(url, `${BASE}/manifest.webmanifest`);
    assert.deepEqual(errors, []);

    const { installabilityErrors } = await page.send('Page.getInstallabilityErrors');
    assert.deepEqual(installabilityErrors, []);
});

test('"Remember me" is ticked by default', async () => {
    await page.goto(`${BASE}/login`);
    assert.equal(await page.evaluate(`document.getElementById('remember').dataset.state`), 'checked');
});

test('a deactivated account still cannot log in', async () => {
    await page.goto(`${BASE}/login`);
    await page.type('input[type="email"]', DEACTIVATED);
    await page.type('input[type="password"]', 'password');
    await page.click('button[type="submit"]', null, 2000);

    assert.equal(await path(), '/login');
    assert.match(await text(), /deactivated/i);
    assert.equal(await rememberCookie(), undefined);
});

test('logged in, the user stays logged in across reloads', async () => {
    await page.login(BASE, EMAIL, 'password');
    assert.equal(await path(), '/dashboard');

    await page.reload();
    assert.equal(await path(), '/dashboard');

    const remember = await rememberCookie();
    assert.ok(remember, 'the "Remember me" cookie is set');
    assert.ok(remember.httpOnly);
    assert.equal(remember.sameSite, 'Lax');
    const days = (remember.expires - Date.now() / 1000) / 86400;
    // Renewed whenever the browser is used; it runs out only after 90 days unused
    assert.ok(days > 89.9 && days < 90.1, `"Remember me" lasts 90 days from the last use (${days.toFixed(2)})`);
    assert.equal((await cookies()).find((cookie) => cookie.name.startsWith('remember_web_')), undefined, "Laravel's own cookie is no longer used");
});

test('nothing private is stored for offline use', async () => {
    await page.goto(`${BASE}/dashboard`);
    await page.goto(`${BASE}/settings/profile`);
    await page.click('a[href="/dashboard"]', null, 1500);

    const stored = await storedPaths();
    assert.ok(stored.includes('/offline.html'));
    assert.ok(stored.some((path) => path.startsWith('/build/assets/')), 'built files are stored');
    for (const path of stored) {
        assert.match(path, /^(\/build\/assets\/[^?]+|\/offline\.html|\/icons\/icon-192\.png|\/favicon\.svg)$/, `${path} must not be stored`);
    }
});

test('offline: it says so and does not pretend anything worked', async () => {
    await page.goto(`${BASE}/dashboard`);
    await offline(true);
    await sleep(500);

    assert.match(await text(), /You're offline\. Pages won't load/);

    // Moving to another page cannot work: the page stays put and says why
    assert.ok(await page.click('a[href="/projects"]', null, 1500), 'a link in the sidebar');
    assert.equal(await path(), '/dashboard');
    assert.match(await text(), /You're offline, so this didn't go through/);

    // Logging out cannot reach the server either, so it does not look as if it did
    assert.ok(await page.click('[data-test="sidebar-menu-button"]', null, 700));
    assert.ok(await page.click('[data-test="logout-button"]', null, 1500));
    assert.equal(await path(), '/dashboard');
    assert.match(await text(), /can't log out yet/);
    await page.press('Escape');

    // Opening a page shows the offline page, never an old copy of a signed-in page
    await page.goto(`${BASE}/dashboard`, 1500);
    assert.equal(await page.evaluate('document.title'), 'Offline - Sidra Desk');
    assert.match(await text(), /Sidra Desk needs a connection/);
    assert.ok(await page.evaluate(`document.querySelector('img').naturalWidth > 0`), 'its icon loads offline too');
    assert.equal(await page.evaluate(`document.querySelector('[data-sidebar], [data-test="sidebar-menu-button"]')`), null);
    await page.screenshot(`${SHOTS}/offline-page.png`, false);

    await offline(false);
    workerSocket.close();
    workerSocket = null;
    await page.goto(`${BASE}/dashboard`);
    assert.equal(await path(), '/dashboard', 'still logged in once back online');
});

test('browser restarts keep the user logged in, even once the session itself has ended', async () => {
    const before = (await rememberCookie()).value;

    // Twice: the second time with the new secret the browser was given the first time
    for (const restart of [1, 2]) {
        // Without its session cookie the browser is like one coming back after the session expired
        await page.send('Network.deleteCookies', { name: SESSION_COOKIE, url: BASE });
        await page.close();

        page = await launch({ profile });
        await page.goto(`${BASE}/dashboard`, 2000);

        assert.equal(await path(), '/dashboard', `still logged in after restart ${restart}`);
        assert.ok(await rememberCookie(), 'the "Remember me" cookie survived the restart');
    }

    assert.notEqual((await rememberCookie()).value, before, 'the cookie was renewed along the way (that the secret inside changes is tested in StayingSignedInTest)');
});

test('logging out works and ends "Remember me"', async () => {
    assert.ok(await page.click('[data-test="sidebar-menu-button"]', null, 700));
    assert.ok(await page.click('[data-test="logout-button"]', null, 2500));

    assert.equal(await path(), '/', 'lands on the welcome page');
    assert.equal(await rememberCookie(), undefined);

    for (const protectedPage of ['/dashboard', '/settings/profile']) {
        await page.goto(`${BASE}${protectedPage}`);
        assert.equal(await path(), '/login', `${protectedPage} needs logging in again`);
    }

    const api = await page.evaluate(`fetch('/api/user', { headers: { Accept: 'application/json' } }).then((r) => r.status)`);
    assert.equal(api, 401);
});

test('on a phone: no sideways scrolling, and the iPhone install steps', async () => {
    await page.close();
    page = await launch({ width: 390, height: 844, mobile: true });
    await page.send('Emulation.setUserAgentOverride', { userAgent: IPHONE, platform: 'iPhone' });
    await page.send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 });
    // Chrome offers its own install window even when pretending to be an iPhone; Safari never does
    await page.send('Page.addScriptToEvaluateOnNewDocument', {
        source: `window.addEventListener('beforeinstallprompt', (event) => event.stopImmediatePropagation(), true);`,
    });

    await page.goto(`${BASE}/login`);
    assert.deepEqual(await cutOff(), [], 'the login page fits a phone screen');
    await page.screenshot(`${SHOTS}/phone-login.png`, false);

    await page.login(BASE, EMAIL, 'password');
    const issue = await anIssue();
    assert.ok(issue, 'the demo data has an issue to open');

    for (const visit of ['/dashboard', '/projects', '/settings/profile', '/settings/appearance', issue]) {
        await page.goto(`${BASE}${visit}`, 1800);
        assert.deepEqual(await cutOff(), [], `${visit} fits a phone screen`);
        await page.screenshot(`${SHOTS}/phone${visit.replaceAll('/', '-')}.png`, false);
    }

    // Safari on iPhone has no install button, so the app explains the Share menu instead
    await page.goto(`${BASE}/dashboard`);
    assert.match(await text(), /Install Sidra Desk on this device/);
    await page.click('[aria-label="Install the app"] button', 'Install', 800);
    assert.match(await text(), /Add to Home Screen/);
    await page.screenshot(`${SHOTS}/phone-install-steps.png`, false);
    await page.press('Escape');

    // "Not now" hides the offer, and it stays hidden
    await page.click('[aria-label="Not now"]', null, 500);
    await page.reload();
    assert.doesNotMatch(await text(), /Install Sidra Desk on this device/);
});

test('on a desktop: no sideways scrolling', async () => {
    await page.close();
    page = await launch({ width: 1440, height: 1000 });
    await page.login(BASE, EMAIL, 'password');

    for (const visit of ['/dashboard', '/projects', '/settings/profile', await anIssue()]) {
        await page.goto(`${BASE}${visit}`, 1800);
        assert.deepEqual(await cutOff(), [], `${visit} fits a desktop window`);
    }
    await page.screenshot(`${SHOTS}/desktop-dashboard.png`, false);
});
