import { router } from '@inertiajs/react';

/**
 * CSRF protection.
 *
 * The server keeps the current token in the XSRF-TOKEN cookie and refreshes it on every
 * response, so the cookie is always right, even after logging out and in again without a
 * full page reload. Inertia sends it automatically. The <meta name="csrf-token"> tag, on the
 * other hand, is only written when the page is first loaded and goes stale after that, so it
 * must never be forced onto requests: the server would trust it over the cookie and answer
 * 419 "Session Expired".
 */
function tokenFromCookie(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}

// fetch() does not send the cookie's token by itself, so add it to requests that carry no token
const originalFetch = window.fetch;
window.fetch = function (input, init = {}) {
    const headers = new Headers(init.headers);
    const cookieToken = tokenFromCookie();

    if (!headers.has('X-CSRF-TOKEN') && !headers.has('X-XSRF-TOKEN') && cookieToken) {
        headers.set('X-XSRF-TOKEN', cookieToken);
    }

    return originalFetch(input, {
        ...init,
        headers,
        credentials: 'same-origin',
    });
};

// Some forms still read the token from the meta tag. Every page the server sends carries
// the current token, so keep the tag in step with it as the user moves around.
router.on('navigate', (event) => {
    const token = (event.detail.page.props as { csrfToken?: string }).csrfToken;
    const meta = document.querySelector('meta[name="csrf-token"]');

    if (token && meta) {
        meta.setAttribute('content', token);
    }
});

// If the session really has ended (for example after hours of inactivity), reload the page
// instead of showing the raw "Session Expired" page inside a pop-up: the reload either
// carries on with a fresh token or lands on the login page.
router.on('invalid', (event) => {
    if (event.detail.response.status === 419) {
        event.preventDefault();
        window.location.reload();
    }
});
