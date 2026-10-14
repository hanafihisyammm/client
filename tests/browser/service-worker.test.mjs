/**
 * What the service worker (public/sw.js) does with each kind of request. It runs the real file
 * against stand-ins for the browser's cache and network, so no browser or server is needed:
 *   node --test tests/browser/service-worker.test.mjs
 *
 * The rule being checked: only files that are the same for everyone are ever stored, so one
 * person's pages or data can never be shown to the next person on the same device.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { beforeEach, test } from 'node:test';
import vm from 'node:vm';

const SOURCE = readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8');
const ORIGIN = 'https://desk.example.com';
const PRECACHED = ['/offline.html', '/icons/icon-192.png', '/favicon.svg'];

let worker;

/** Load sw.js into a fresh, fake service worker environment. */
function load() {
    const listeners = {};
    const stores = new Map();
    const network = { offline: false, requests: [], status: () => 200 };
    const key = (request) => new URL(typeof request === 'string' ? request : request.url, ORIGIN).href;

    const cacheFor = (name) => {
        if (!stores.has(name)) stores.set(name, new Map());
        const store = stores.get(name);

        return {
            match: async (request) => store.get(key(request))?.clone(),
            put: async (request, response) => void store.set(key(request), response),
            delete: async (request) => store.delete(key(request)),
            keys: async () => [...store.keys()].map((url) => new Request(url)),
            addAll: async (requests) => {
                for (const request of requests) store.set(key(request), await fakeFetch(request));
            },
        };
    };

    async function fakeFetch(request) {
        const url = key(request);
        network.requests.push(url);
        if (network.offline) throw new TypeError('Failed to fetch');

        const status = network.status(url);
        const response = new Response(`body of ${url}`, { status });
        // Responses from the site itself are "basic"; others would be "cors" or "opaque"
        Object.defineProperty(response, 'type', { value: new URL(url).origin === ORIGIN ? 'basic' : 'cors' });

        return response;
    }

    // Relative URLs resolve against the service worker's own address, as in a browser
    class WorkerRequest extends Request {
        constructor(input, init) {
            super(typeof input === 'string' ? new URL(input, ORIGIN) : input, init);
        }
    }

    // What the alerts did: notifications shown, the app icon's number, windows focused or opened
    const alerts = { shown: [], badge: undefined, windows: [], opened: [] };

    const self = {
        location: new URL(`${ORIGIN}/sw.js`),
        addEventListener: (type, listener) => (listeners[type] = listener),
        skipWaiting: async () => {},
        clients: {
            claim: async () => {},
            matchAll: async () => alerts.windows,
            openWindow: async (url) => alerts.opened.push(url),
        },
        registration: { showNotification: async (title, options) => alerts.shown.push({ title, ...options }) },
        navigator: {
            setAppBadge: async (count) => (alerts.badge = count),
            clearAppBadge: async () => (alerts.badge = 0),
        },
    };

    const caches = {
        open: async (name) => cacheFor(name),
        keys: async () => [...stores.keys()],
        delete: async (name) => stores.delete(name),
        match: async (request) => {
            for (const name of stores.keys()) {
                const found = await cacheFor(name).match(request);
                if (found) return found;
            }
        },
    };

    vm.runInNewContext(SOURCE, { self, caches, fetch: fakeFetch, Request: WorkerRequest, Response, URL, Promise, Math });

    const lifecycle = async (type) => {
        let done;
        listeners[type]({ waitUntil: (promise) => (done = promise) });
        await done;
    };

    return {
        network,
        stores,
        install: () => lifecycle('install'),
        activate: () => lifecycle('activate'),
        /** Send a request through the worker. Resolves to null when the worker leaves it to the browser. */
        async request(path, { method = 'GET', mode = 'cors', headers = {} } = {}) {
            const request = { url: new URL(path, ORIGIN).href, method, mode, headers: new Headers(headers) };
            let response = null;
            listeners.fetch({ request, respondWith: (value) => (response = Promise.resolve(value)) });

            return response;
        },
        alerts,
        /** An alert arriving from the push service. */
        async push(payload) {
            let done;
            const data = payload === undefined ? null : { json: () => (typeof payload === 'string' ? JSON.parse(payload) : payload) };
            listeners.push({ data, waitUntil: (promise) => (done = promise) });
            await done;
        },
        /** Clicking an alert. */
        async click(data) {
            let done;
            let closed = false;
            listeners.notificationclick({ notification: { data, close: () => (closed = true) }, waitUntil: (promise) => (done = promise) });
            await done;
            return closed;
        },
        /** Every address stored in any cache. */
        stored: () => [...stores.values()].flatMap((store) => [...store.keys()].map((url) => new URL(url).pathname + new URL(url).search)),
    };
}

/** Nothing but the offline page, its icons and built files may ever be stored. */
const assertOnlyPublicFilesStored = () => {
    for (const path of worker.stored()) {
        assert.ok(PRECACHED.includes(path) || /^\/build\/assets\/[^?]+$/.test(path), `${path} must not be stored`);
    }
};

beforeEach(async () => {
    worker = load();
    await worker.install();
    await worker.activate();
    worker.network.requests.length = 0;
});

test('installing stores the offline page and its icons', () => {
    assert.deepEqual(worker.stored().sort(), [...PRECACHED].sort());
});

test('pages always come from the server and are never stored', async () => {
    for (const path of ['/dashboard', '/reports/0b6e', '/clients/acme', '/settings/profile', '/login', '/']) {
        const response = await worker.request(path, { mode: 'navigate' });
        assert.equal(await response.text(), `body of ${ORIGIN}${path}`);
    }

    assert.equal(worker.network.requests.length, 6);
    assertOnlyPublicFilesStored();
});

test('a page that cannot be loaded offline shows the offline page, not an old copy', async () => {
    await worker.request('/dashboard', { mode: 'navigate' });
    worker.network.offline = true;

    const response = await worker.request('/dashboard', { mode: 'navigate' });

    assert.equal(await response.text(), `body of ${ORIGIN}/offline.html`);
});

test('page data, API calls and uploaded files are left to the browser and never stored', async () => {
    const untouched = [
        ['/dashboard', { headers: { 'X-Inertia': 'true' } }],
        ['/reports/0b6e', { headers: { 'X-Inertia': 'true', 'X-Inertia-Partial-Data': 'comments' } }],
        ['/api/notifications', {}],
        ['/api/user', {}],
        ['/api/reports?status=open', {}],
        ['/storage/attachments/screenshot.png', {}],
        ['/manifest.webmanifest', {}],
        ['/sw.js', {}],
    ];

    for (const [path, options] of untouched) {
        assert.equal(await worker.request(path, options), null, `${path} is left to the browser`);
    }

    assert.equal(worker.network.requests.length, 0);
    assertOnlyPublicFilesStored();
});

test('nothing that sends or changes data goes through the worker, online or offline', async () => {
    for (const offline of [false, true]) {
        worker.network.offline = offline;

        for (const method of ['POST', 'PUT', 'PATCH', 'DELETE']) {
            for (const path of ['/login', '/logout', '/api/reports', '/settings/password', '/reports/0b6e']) {
                assert.equal(await worker.request(path, { method, mode: 'navigate' }), null, `${method} ${path}`);
                assert.equal(await worker.request(path, { method }), null, `${method} ${path}`);
            }
        }
    }

    assert.equal(worker.network.requests.length, 0);
});

test('other sites are left alone', async () => {
    assert.equal(await worker.request('https://fonts.bunny.net/css?family=dm-sans'), null);
    assert.equal(await worker.request('https://evil.example.org/build/assets/app.js'), null);
});

test('the offline page and its icon still load without a connection', async () => {
    const online = await worker.request('/icons/icon-192.png');
    assert.equal(await online.text(), `body of ${ORIGIN}/icons/icon-192.png`);
    assert.equal(worker.network.requests.length, 1, 'fetched fresh while online');

    worker.network.offline = true;
    for (const path of PRECACHED) {
        const response = await worker.request(path);
        assert.equal(await response.text(), `body of ${ORIGIN}${path}`, `${path} from the stored copy`);
    }
    assertOnlyPublicFilesStored();
});

test('built files are stored once and then served without the network', async () => {
    const first = await worker.request('/build/assets/app-CLYTKXV6.js');
    assert.equal(await first.text(), `body of ${ORIGIN}/build/assets/app-CLYTKXV6.js`);

    worker.network.offline = true;
    const again = await worker.request('/build/assets/app-CLYTKXV6.js');
    assert.equal(await again.text(), `body of ${ORIGIN}/build/assets/app-CLYTKXV6.js`);

    // Only the first one went to the network
    assert.equal(worker.network.requests.length, 1);
    assertOnlyPublicFilesStored();
});

test('failed answers are not stored', async () => {
    worker.network.status = (url) => (url.includes('missing') ? 404 : 500);

    await worker.request('/build/assets/missing-abc.js');
    await worker.request('/build/assets/broken-abc.js');

    assert.deepEqual(worker.stored().sort(), [...PRECACHED].sort());
});

test('built files asked for with a query string are not stored', async () => {
    assert.equal(await worker.request('/build/assets/app.js?user=7'), null);
    assertOnlyPublicFilesStored();
});

test('old builds are trimmed so the cache does not grow forever', async () => {
    for (let index = 0; index < 130; index++) {
        await worker.request(`/build/assets/chunk-${index}.js`);
    }

    const built = worker.stored().filter((path) => path.startsWith('/build/'));
    assert.equal(built.length, 120);
    assert.ok(!built.includes('/build/assets/chunk-0.js'), 'the oldest files go first');
    assert.ok(built.includes('/build/assets/chunk-129.js'));
    // The offline page is never trimmed
    for (const path of PRECACHED) assert.ok(worker.stored().includes(path));
});

test('a new version clears the caches of the old one, and no one else\'s', async () => {
    worker.stores.set('sidra-desk-static-v0', new Map([[`${ORIGIN}/build/assets/old.js`, new Response('old')]]));
    worker.stores.set('another-app', new Map());

    await worker.activate();

    assert.deepEqual([...worker.stores.keys()].sort(), ['another-app', 'sidra-desk-static-v1']);
});

test('an alert shows the notification and the unread count on the app icon', async () => {
    await worker.push({
        title: 'New comment: Checkout button',
        body: 'Ben: It still fails on Safari',
        icon: '/icons/icon-192.png',
        badge: '/icons/badge-96.png',
        tag: 'abc-123',
        data: { url: '/reports/0b6e', id: 'abc-123', unread: 3 },
    });

    assert.equal(worker.alerts.shown.length, 1);
    const [shown] = worker.alerts.shown;
    assert.equal(shown.title, 'New comment: Checkout button');
    assert.equal(shown.body, 'Ben: It still fails on Safari');
    assert.equal(shown.tag, 'abc-123');
    assert.deepEqual({ ...shown.data }, { url: '/reports/0b6e' });
    assert.equal(worker.alerts.badge, 3);

    await worker.push({ title: 'x', data: { unread: 0 } });
    assert.equal(worker.alerts.badge, 0, 'the number goes once everything is read');
});

test('an alert that cannot be read still shows something, as browsers require', async () => {
    await worker.push('{not json');
    await worker.push(undefined);

    assert.equal(worker.alerts.shown.length, 2);
    for (const shown of worker.alerts.shown) {
        assert.equal(shown.title, 'Sidra Desk');
        assert.equal(shown.data.url, '/dashboard');
    }
});

test('an alert never links to another site', async () => {
    for (const url of ['https://evil.example/x', '//evil.example/x', 'javascript:alert(1)', '/\\evil.example', 42, null]) {
        await worker.push({ title: 'x', data: { url } });
    }

    assert.ok(worker.alerts.shown.every((shown) => shown.data.url === '/dashboard'));
});

test('alerts are not stored anywhere', async () => {
    await worker.push({ title: 'Private title', data: { url: '/reports/0b6e' } });
    assertOnlyPublicFilesStored();
});

test('clicking an alert opens its page in the app', async () => {
    const closed = await worker.click({ url: '/reports/0b6e' });

    assert.ok(closed, 'the alert is dismissed');
    assert.deepEqual(worker.alerts.opened, [`${ORIGIN}/reports/0b6e`]);
});

test('clicking an alert brings an open window forward instead of opening another', async () => {
    const navigated = [];
    const open = { url: `${ORIGIN}/dashboard`, focus: async () => open, navigate: async (url) => navigated.push(url) };
    worker.alerts.windows.push({ url: 'https://other.example/', focus: async () => assert.fail('another site is never used') }, open);

    await worker.click({ url: '/reports/0b6e' });

    assert.deepEqual(navigated, [`${ORIGIN}/reports/0b6e`]);
    assert.deepEqual(worker.alerts.opened, []);
});

test('clicking an alert with a foreign link opens the dashboard', async () => {
    await worker.click({ url: 'https://evil.example/phish' });
    await worker.click(undefined);

    assert.deepEqual(worker.alerts.opened, [`${ORIGIN}/dashboard`, `${ORIGIN}/dashboard`]);
});
