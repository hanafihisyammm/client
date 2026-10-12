/**
 * Opens every main page in a real browser, in the light and the dark theme, and reports:
 *   - text that is hard to read against its background (WCAG contrast ratio)
 *   - in the dark theme, large surfaces that are still light (a page "half light, half dark")
 * It also saves a screenshot of each page and state for looking at by eye.
 *
 * Needs the local site running with the demo data:
 *   node tests/browser/theme-audit.mjs [output-folder] [base-url]
 */
/* global process */
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { launch, sleep } from './lib.mjs';

const OUT = process.argv[2] || 'storage/app/theme-audit';
const BASE = process.argv[3] || 'http://127.0.0.1:8000';
const ONLY = process.env.ONLY ? process.env.ONLY.split(',') : null;
mkdirSync(OUT, { recursive: true });

// Runs inside the page. Works out the real colour behind every piece of text.
const AUDIT = `(() => {
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 1;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    const cache = new Map();
    // Any CSS colour (rgb, oklab, color-mix ...) to [r, g, b, alpha]
    const rgba = (color) => {
        if (cache.has(color)) return cache.get(color);
        ctx.clearRect(0, 0, 1, 1);
        ctx.fillStyle = 'rgba(0,0,0,0)';
        ctx.fillStyle = color;
        ctx.fillRect(0, 0, 1, 1);
        const d = ctx.getImageData(0, 0, 1, 1).data;
        const value = [d[0], d[1], d[2], d[3] / 255];
        cache.set(color, value);
        return value;
    };
    const over = (top, bottom) => {
        const a = top[3];
        return [0, 1, 2].map((i) => top[i] * a + bottom[i] * (1 - a)).concat(1);
    };
    const luminance = ([r, g, b]) => {
        const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
        return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
    };
    const contrast = (a, b) => {
        const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
        return (hi + 0.05) / (lo + 0.05);
    };
    const pageBackground = rgba(getComputedStyle(document.documentElement).backgroundColor);
    // The colour actually behind an element: its ancestors' backgrounds laid over each other
    const backgroundOf = (element) => {
        const layers = [];
        let picture = false;
        for (let node = element; node; node = node.parentElement) {
            const style = getComputedStyle(node);
            if (style.backgroundImage !== 'none') { picture = true; break; }
            const colour = rgba(style.backgroundColor);
            if (colour[3] > 0) layers.push(colour);
            if (colour[3] >= 0.99) break;
        }
        return { colour: layers.reverse().reduce((under, layer) => over(layer, under), pageBackground), picture };
    };
    const describe = (element) => (element.tagName.toLowerCase() + '.' + String(element.className?.baseVal ?? element.className ?? '').trim().split(/\\s+/).slice(0, 14).join('.')).slice(0, 190);
    const hex = (c) => '#' + c.slice(0, 3).map((v) => Math.round(v).toString(16).padStart(2, '0')).join('');

    const failures = new Map();
    const surfaces = new Map();
    const dark = document.documentElement.classList.contains('dark');

    for (const element of document.body.querySelectorAll('*')) {
        const box = element.getBoundingClientRect();
        if (box.width < 3 || box.height < 3) continue;
        const style = getComputedStyle(element);
        if (style.visibility === 'hidden' || style.display === 'none') continue;

        // A large surface that is still light while the theme is dark
        // (the logo is the exception: its dark lines need the white tile it sits on)
        if (dark && element.tagName !== 'IMG' && box.width * box.height > 2500 && style.backgroundImage === 'none') {
            const own = rgba(style.backgroundColor);
            if (own[3] > 0.5 && luminance(over(own, pageBackground)) > 0.4) {
                surfaces.set(describe(element), { element: describe(element), background: hex(own), size: Math.round(box.width) + 'x' + Math.round(box.height) });
            }
        }

        const text = [...element.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent.trim()).join(' ').trim();
        const isField = ['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName);
        if (!text && !isField) continue;

        // Faded on purpose (a deactivated row, a disabled button): judged by its own design
        let opacity = 1;
        for (let node = element; node; node = node.parentElement) opacity *= parseFloat(getComputedStyle(node).opacity);
        if (opacity < 0.95) continue;

        const { colour: background, picture } = backgroundOf(element);
        if (picture) continue;

        const colour = over(rgba(style.color), background);
        const ratio = contrast(colour, background);
        const size = parseFloat(style.fontSize);
        const large = size >= 24 || (size >= 18.66 && parseInt(style.fontWeight, 10) >= 700);
        const needed = large ? 3 : 4.5;

        // (a hair of tolerance: 4.499 is a rounding artefact, not a different colour)
        if (ratio < needed - 0.01) {
            const key = describe(element) + hex(colour) + hex(background);
            if (!failures.has(key)) {
                failures.set(key, { ratio: Math.round(ratio * 100) / 100, needed, text: (text || element.placeholder || element.value || '').slice(0, 40), colour: hex(colour), background: hex(background), element: describe(element) });
            }
        }
    }

    return { dark, failures: [...failures.values()].sort((a, b) => a.ratio - b.ratio), surfaces: [...surfaces.values()] };
})()`;

const props = (page) => page.evaluate(`JSON.parse(document.getElementById('app').dataset.page).props`);

const page = await launch();
const report = [];

async function inspect(name, theme) {
    const result = await page.evaluate(AUDIT);
    await page.screenshot(join(OUT, `${theme}-${name}.png`));
    report.push({ page: name, theme, isDark: result.dark, failures: result.failures, surfaces: result.surfaces });
    const flag = result.failures.length || result.surfaces.length ? '!!' : 'ok';
    console.log(`${flag} ${theme.padEnd(5)} ${name.padEnd(34)} low contrast: ${String(result.failures.length).padStart(2)}   light surfaces: ${result.surfaces.length}`);
}

// Signed out, the theme comes from the browser's cookie. Signed in, it is the account's saved
// choice, which wins over the cookie, so it has to be set there (and put back afterwards).
let signedIn = false;
let originalChoice = null;

const saveChoice = (theme) => page.evaluate(`fetch('/settings/appearance', {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ appearance: ${JSON.stringify(theme)} }),
}).then((response) => response.status)`);

const setTheme = async (theme) => {
    await page.cookie('appearance', theme, BASE);
    await page.systemTheme(theme);
    if (signedIn) await saveChoice(theme);
};

const visitIn = (theme) => async (name, path, actions = async () => {}) => {
    if (ONLY && !ONLY.some((part) => name.includes(part))) return;
    await page.goto(BASE + path);
    await actions();
    await inspect(name, theme);
};

try {
    // ---- Signed out
    for (const theme of ['dark', 'light']) {
        await setTheme(theme);
        const visit = visitIn(theme);

        await visit('welcome', '/');
        await visit('login', '/login');
        await visit('login-errors', '/login', async () => { await page.type('input[type="email"]', 'nobody@example.com'); await page.type('input[type="password"]', 'wrong'); await page.click('button[type="submit"]', null, 1500); });
        await visit('forgot-password', '/forgot-password');
    }

    // ---- Super Admin: every area of the app
    await page.login(BASE, 'samantha@sidracore.com', 'password');
    signedIn = true;
    // A fresh page load, so this is the account's saved choice and not the login page's
    await page.goto(BASE + '/settings/appearance');
    originalChoice = await page.evaluate('document.documentElement.dataset.appearance');
    await page.goto(BASE + '/projects');
    const project = (await props(page)).projects[0];
    await page.goto(BASE + '/clients');
    const company = (await props(page)).companies.find((c) => c.staff_count > 1);
    await page.goto(BASE + '/users');
    const user = (await props(page)).users.find((u) => u.role === 'staff' && !u.deactivated_at);
    const issues = await page.evaluate(`fetch('/api/reports?per_page=50', { headers: { Accept: 'application/json' } }).then((r) => r.json()).then((j) => j.data)`);
    const issue = issues.find((i) => i.status === 'resolved') || issues[0];

    for (const theme of ['dark', 'light']) {
        await setTheme(theme);
        const visit = visitIn(theme);

        await visit('issues-board', '/dashboard');
        await visit('issues-list', '/dashboard', () => page.click('button', 'List', 900));
        await visit('issues-filter-open', '/dashboard', () => page.click('[role="combobox"]', 'All Status', 600));
        await visit('notifications-open', '/dashboard', () => page.click('button[aria-label*="otification"]', null, 800));
        await visit('user-menu-open', '/dashboard', () => page.click('[data-sidebar="footer"] button', null, 700));
        await visit('issue-create', '/reports/create');
        await visit('issue-create-errors', '/reports/create', () => page.click('button[type="submit"]', null, 900));
        await visit('issue-detail', `/reports/${issue.uuid}`);
        await visit('issue-edit', `/reports/${issue.uuid}`, () => page.click('button:has(svg.lucide-pencil)', null, 800));
        await visit('projects', '/projects');
        await visit('projects-row-menu', '/projects', () => page.click('tbody button:has(svg.lucide-ellipsis)', null, 600));
        await visit('projects-date-picker', '/projects', () => page.click('button', 'Created date', 700));
        await visit('project-create', '/projects/create');
        await visit('project-detail', `/projects/view/${project.uuid}`);
        await visit('project-edit', `/projects/${project.uuid}/edit`);
        await visit('clients', '/clients');
        await visit('clients-add-dialog', '/clients', () => page.click('button', 'Add Client', 800));
        await visit('client-staff', `/clients/${company.uuid}`);
        await visit('client-projects', `/clients/${company.uuid}?tab=projects`);
        await visit('client-add-staff-dialog', `/clients/${company.uuid}`, () => page.click('button', 'Add Staff', 800));
        await visit('users', '/users');
        await visit('users-row-menu', '/users', () => page.click('tbody button:has(svg.lucide-ellipsis)', null, 600));
        await visit('users-add', '/users/create');
        await visit('user-detail', `/users/${user.uuid}`);
        await visit('user-edit', `/users/${user.uuid}/edit`);
        await visit('audit-log', '/audit-logs');
        await visit('settings-profile', '/settings/profile');
        await visit('settings-password', '/settings/password');
        await visit('settings-appearance', '/settings/appearance');

        // ---- Signed in as a staff member: the banner, and what staff see
        await page.goto(BASE + '/users');
        await page.type('input[placeholder^="Search by name"]', user.email);
        await sleep(400);
        await page.click('tbody button:has(svg.lucide-ellipsis)', null, 500);
        if (await page.click('[role="menuitem"]', 'Sign in as', 2500)) {
            await visit('impersonating-issues', '/dashboard');
            await visit('impersonating-my-projects', '/projects');
            await page.click('button', 'Return to Super Admin', 2500);
        } else {
            console.log('   (could not open "Sign in as")');
        }
    }
} finally {
    // Leave the account's own theme as it was found
    if (signedIn && originalChoice) await saveChoice(originalChoice).catch(() => {});
    await page.close();
}

writeFileSync(join(OUT, 'report.json'), JSON.stringify(report, null, 2));

const problems = report.filter((entry) => entry.failures.length || entry.surfaces.length || entry.isDark !== (entry.theme === 'dark'));
console.log(`\n${report.length} page states checked, ${problems.length} with something to look at. Details: ${join(OUT, 'report.json')}`);
process.exitCode = problems.length ? 1 : 0;
