/**
 * Looks through the page source for colours that ignore the theme, so a new page cannot quietly
 * bring back a white panel in dark mode. No browser needed:
 *   node --test tests/browser/hardcoded-colours.test.mjs
 */
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { test } from 'node:test';

const ROOT = new URL('../../resources/js', import.meta.url).pathname;

const files = (dir) => readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    return statSync(path).isDirectory() ? files(path) : /\.(tsx|ts)$/.test(name) ? [path] : [];
});

const findings = (pattern, allowed = () => false) => files(ROOT).flatMap((file) =>
    readFileSync(file, 'utf8').split('\n').flatMap((line, index) => {
        const where = `${relative(ROOT, file)}:${index + 1}`;
        return [...line.matchAll(pattern)].filter((match) => !allowed(match, line, where)).map((match) => `${where}  ${match[0]}`);
    }));

test('surfaces use the card colour, not a fixed white', () => {
    // bg-white with nothing before or after it. "dark:bg-white" (the logo tile) and
    // "bg-white/10" (buttons over the black picture viewer) are deliberate.
    assert.deepEqual(findings(/(?<![:\w-])bg-white(?![/\w-])/g), [], 'use bg-card (or bg-background) instead of bg-white');
});

test('no colour is written as a fixed hex code inside a class', () => {
    const allowed = (match, line, where) => where.startsWith('pages/welcome.tsx') && match[0] === 'text-[#e0c98a]';

    assert.deepEqual(findings(/\b(?:bg|text|border|ring|from|to|via|fill|stroke)-\[#[0-9a-fA-F]{3,8}\]/g, allowed), [],
        'use a theme colour (text-foreground, bg-muted, text-brand-gold-dark ...) instead of a hex code');
});

test('no fixed colour is set through an inline style', () => {
    const allowed = (match, line) => line.includes('labelStyle(');

    assert.deepEqual(findings(/style=\{\{[^}]*\b(?:color|background(?:Color)?|borderColor)\s*:\s*['"`#]/g, allowed), [],
        'inline colours do not change with the theme; use a class, or labelStyle() for label chips');
});

test('the theme-aware palette covers every grey and tinted class the pages use', () => {
    const palette = readFileSync(new URL('../../resources/css/theme-colors.css', import.meta.url), 'utf8');
    const namespace = { text: 'text-color', bg: 'background-color', border: 'border-color' };
    // Coloured text from 600 up is too dark for a dark background; tints up to 300 are too pale
    const needsDarkValue = (kind, shade) => (kind === 'text' ? shade >= 600 : shade <= 300);
    const neutral = new Set(['gray', 'slate']);
    const missing = new Set();

    for (const file of files(ROOT)) {
        for (const [, kind, hue, shadeText] of readFileSync(file, 'utf8').matchAll(/(?<![\w-])(?:[a-z-]+:)*(text|bg|border)-(gray|slate|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-(\d{2,3})\b/g)) {
            const shade = Number(shadeText);
            // Greys: light shades used as text and dark shades used as text both need a dark value;
            // surfaces and outlines need one up to 300 (400 for outlines). Hues: see theme-colors.css.
            const required = neutral.has(hue)
                ? (kind === 'text' ? shade >= 200 : kind === 'bg' ? shade <= 300 : shade <= 400)
                : needsDarkValue(kind, shade) && !(kind === 'border' && shade === 50);
            if (required && !palette.includes(`--${namespace[kind]}-${hue}-${shade}:`)) missing.add(`${kind}-${hue}-${shade}`);
        }
    }

    assert.deepEqual([...missing].sort(), [], 'add these to resources/css/theme-colors.css so they have a dark-theme value');
});
