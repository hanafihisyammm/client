/**
 * Light / Dark / System in a real browser: switching, following the computer, surviving a
 * refresh, and surviving a logout and a brand-new browser.
 *
 * Needs the local site running with the demo data:
 *   node --test tests/browser/theme-switching.test.mjs
 */
/* global process */
import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { launch, sleep } from './lib.mjs';

const BASE = process.env.APP_URL || 'http://127.0.0.1:8000';
const EMAIL = 'acme.staff1@example.com';
const DARK_BACKGROUND = 'rgb(14, 17, 22)';
const LIGHT_BACKGROUND = 'rgb(247, 247, 244)';

let page;

/** What the page looks like right now. */
const look = () => page.evaluate(`({
    choice: document.documentElement.dataset.appearance,
    dark: document.documentElement.classList.contains('dark'),
    background: getComputedStyle(document.body).backgroundColor,
    // What the browser uses for its own controls: scrollbars, date pickers, autofill
    controls: getComputedStyle(document.documentElement).colorScheme,
    card: (() => { const el = document.querySelector('.bg-card'); return el ? getComputedStyle(el).backgroundColor : null; })(),
})`);

const choose = async (label) => {
    assert.ok(await page.click('[role="radiogroup"] [role="radio"]', label, 900), `the ${label} option is on the page`);
};

const selected = () => page.evaluate(`document.querySelector('[role="radio"][aria-checked="true"]')?.textContent.trim()`);

before(async () => {
    page = await launch();
});

after(async () => {
    // Leave the demo account as it was found
    await page.goto(`${BASE}/settings/appearance`);
    await page.click('[role="radiogroup"] [role="radio"]', 'System', 900);
    await page.close();
});

test('a new visitor gets the computer\'s theme, and it follows the computer live', async () => {
    await page.systemTheme('dark');
    await page.goto(`${BASE}/login`);
    assert.deepEqual(await look().then(({ choice, dark, background, controls }) => ({ choice, dark, background, controls })),
        { choice: 'system', dark: true, background: DARK_BACKGROUND, controls: 'dark' });

    // The computer switches to light while the page is open: no reload needed
    await page.systemTheme('light');
    const now = await look();
    assert.equal(now.dark, false);
    assert.equal(now.background, LIGHT_BACKGROUND);
    assert.equal(now.controls, 'light');
});

test('Dark is always dark, whatever the computer is set to', async () => {
    await page.systemTheme('light');
    await page.login(BASE, EMAIL, 'password');
    await page.goto(`${BASE}/settings/appearance`);

    await choose('Dark');

    const now = await look();
    assert.equal(now.choice, 'dark');
    assert.equal(now.dark, true);
    assert.equal(now.background, DARK_BACKGROUND);
    assert.equal(now.controls, 'dark');
    assert.equal(await selected(), 'Dark');
    assert.notEqual(now.card, 'rgb(255, 255, 255)', 'cards are not left white');

    await page.systemTheme('dark');
    await page.systemTheme('light');
    assert.equal((await look()).dark, true, 'a change on the computer does not override the choice');
});

test('the choice survives a refresh, and the page arrives already dark', async () => {
    await page.reload();
    assert.equal((await look()).dark, true);
    assert.equal(await selected(), 'Dark');

    // The server sends the class on <html> itself, so there is no light flash before scripts run
    const html = await page.evaluate(`fetch('/dashboard').then((r) => r.text())`);
    assert.match(html, /<html[^>]*data-appearance="dark"[^>]*class="dark"/);
});

test('every page uses the chosen theme, not only the settings page', async () => {
    for (const path of ['/dashboard', '/projects', '/reports/create', '/settings/profile']) {
        await page.goto(BASE + path);
        const now = await look();
        assert.equal(now.dark, true, `${path} is dark`);
        assert.equal(now.background, DARK_BACKGROUND, `${path} has the dark background`);
    }
});

test('Light is always light, even on a computer set to dark', async () => {
    await page.systemTheme('dark');
    await page.goto(`${BASE}/settings/appearance`);

    await choose('Light');

    let now = await look();
    assert.equal(now.choice, 'light');
    assert.equal(now.dark, false);
    assert.equal(now.background, LIGHT_BACKGROUND);
    assert.equal(now.controls, 'light');
    assert.equal(now.card, 'rgb(255, 255, 255)');

    await page.reload();
    now = await look();
    assert.equal(now.dark, false, 'still light after a refresh');
    assert.equal(await selected(), 'Light');
});

test('System follows the computer in both directions', async () => {
    await page.systemTheme('dark');
    await choose('System');
    assert.equal((await look()).dark, true);
    assert.equal(await selected(), 'System');

    await page.systemTheme('light');
    assert.equal((await look()).dark, false);

    await page.reload();
    assert.equal((await look()).choice, 'system');
    assert.equal((await look()).dark, false);

    await page.systemTheme('dark');
    assert.equal((await look()).dark, true);
});

test('the choice is kept after logging out, and comes back on a browser that has never seen it', async () => {
    await page.systemTheme('light');
    await page.goto(`${BASE}/settings/appearance`);
    await choose('Dark');

    await page.evaluate(`fetch('/logout', { method: 'POST' })`);
    await sleep(900);

    // Same browser, logged out: the login page keeps the theme
    await page.goto(`${BASE}/login`);
    assert.equal(await page.evaluate('location.pathname'), '/login');
    assert.equal((await look()).dark, true, 'login page is dark after logging out');

    // A different browser: nothing stored at all, so it starts out following the computer
    await page.send('Network.clearBrowserCookies');
    await page.evaluate('localStorage.clear()');
    await page.goto(`${BASE}/login`);
    assert.equal((await look()).dark, false, 'a fresh browser starts light on a light computer');

    // Logging in happens without a full page load; the saved theme arrives with it
    await page.login(BASE, EMAIL, 'password');
    const now = await look();
    assert.equal(now.choice, 'dark');
    assert.equal(now.dark, true, 'the saved theme is applied straight after logging in');

    await page.reload();
    assert.equal((await look()).dark, true);
});
