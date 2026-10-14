/**
 * Sidra Desk service worker: what makes the site installable as an app.
 *
 * It deliberately stores very little. Pages, Inertia responses, API responses and uploaded
 * files belong to whoever is signed in, so they always come from the server and are never
 * kept here, where the next person using the same device could see them. The only things
 * stored are files that are the same for everyone:
 *   - the built JavaScript and CSS under /build/assets (their names change with their content)
 *   - the offline page and its icon, shown when a page cannot be loaded without a connection
 *
 * Only GET requests are ever looked at, so nothing that changes data can look as if it
 * worked while offline: it simply fails, as it would without a service worker.
 *
 * It also shows phone / desktop alerts sent by the server (Web Push), and opens the
 * notification's page on this site when one is clicked.
 *
 * Change VERSION whenever this file or offline.html changes.
 */
const VERSION = 'v1';
const CACHE_PREFIX = 'sidra-desk-';
const CACHE = `${CACHE_PREFIX}static-${VERSION}`;
const OFFLINE_PAGE = '/offline.html';
const PRECACHE = [OFFLINE_PAGE, '/icons/icon-192.png', '/favicon.svg'];
// Old builds pile up after each deployment; keep only the most recent files
const MAX_BUILD_FILES = 120;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE)
            .then((cache) => cache.addAll(PRECACHE.map((path) => new Request(path, { cache: 'reload' }))))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((names) => Promise.all(names.filter((name) => name.startsWith(CACHE_PREFIX) && name !== CACHE).map((name) => caches.delete(name))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Opening a page: always from the server, never stored; the offline page if there is no connection
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => offlinePage()));
        return;
    }

    if (isBuildFile(url)) {
        event.respondWith(fromCacheOrNetwork(request));
        return;
    }

    // The offline page's own icon: fresh from the server, or the stored copy without a connection
    if (PRECACHE.includes(url.pathname) && url.search === '') {
        event.respondWith(fetch(request).catch(() => caches.match(url.pathname).then((cached) => cached || Response.error())));
    }

    // Everything else (Inertia page data, /api, /storage, ...) goes to the server untouched
});

// Phone / desktop alerts (Web Push). The server sends the notification's title, message and
// the page to open; nothing is stored here.
self.addEventListener('push', (event) => {
    let alert = {};
    try {
        alert = event.data ? event.data.json() : {};
    } catch {
        // Unreadable: still show something, as browsers require for every push
    }

    const data = alert.data || {};

    event.waitUntil(
        Promise.all([
            self.registration.showNotification(alert.title || 'Sidra Desk', {
                body: alert.body || 'You have a new notification.',
                icon: alert.icon || '/icons/icon-192.png',
                badge: alert.badge || '/icons/badge-96.png',
                tag: alert.tag,
                data: { url: pathOnThisSite(data.url) },
            }),
            showUnreadCount(data.unread),
        ]),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(pathOnThisSite(event.notification.data && event.notification.data.url), self.location.origin).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            // Bring an open window of the app forward and show the page there
            const open = windows.find((client) => new URL(client.url).origin === self.location.origin);
            if (open) {
                return open.focus().then((client) => (client.navigate ? client.navigate(url) : client));
            }

            return self.clients.openWindow(url);
        }),
    );
});

/** A path on this site, never another site's address; the dashboard otherwise. */
function pathOnThisSite(path) {
    return typeof path === 'string' && /^\/(?!\/)/.test(path) && !path.includes('\\') ? path : '/dashboard';
}

/** The number on the installed app's icon, where the system shows one. */
function showUnreadCount(count) {
    const navigator = self.navigator;
    if (typeof count !== 'number' || !navigator || !navigator.setAppBadge) return Promise.resolve();

    return (count > 0 ? navigator.setAppBadge(count) : navigator.clearAppBadge()).catch(() => {});
}

function isBuildFile(url) {
    return url.pathname.startsWith('/build/assets/') && url.search === '';
}

async function offlinePage() {
    const cached = await caches.match(OFFLINE_PAGE);

    return cached || new Response("You're offline.", { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
}

async function fromCacheOrNetwork(request) {
    const cache = await caches.open(CACHE);
    const cached = await cache.match(request);
    if (cached) return cached;

    const response = await fetch(request);

    if (response.ok && response.type === 'basic') {
        await cache.put(request, response.clone());
        await trim(cache);
    }

    return response;
}

async function trim(cache) {
    const buildFiles = (await cache.keys()).filter((request) => isBuildFile(new URL(request.url)));
    const excess = buildFiles.length - MAX_BUILD_FILES;

    // Oldest first: a cache lists its entries in the order they were added
    for (const request of buildFiles.slice(0, Math.max(0, excess))) {
        await cache.delete(request);
    }
}
