/**
 * A very small driver for a real, headless Chrome, using only what ships with Node 22+
 * (no extra packages). Enough to log in through the real login form, switch between the
 * light and dark themes, read what the page looks like, and take screenshots.
 */
/* global process, Buffer */
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const CHROME = process.env.CHROME_BIN || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export async function launch({ width = 1440, height = 1000 } = {}) {
    const profile = mkdtempSync(join(tmpdir(), 'sidra-browser-'));
    const port = 9300 + Math.floor(Math.random() * 500);
    const chrome = spawn(CHROME, [
        '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`,
        `--window-size=${width},${height}`, '--no-first-run', '--no-default-browser-check', '--hide-scrollbars', 'about:blank',
    ], { stdio: 'ignore' });

    let targets;
    for (let attempt = 0; attempt < 50 && !targets; attempt++) {
        await sleep(200);
        targets = await fetch(`http://127.0.0.1:${port}/json`).then((r) => r.json()).catch(() => null);
    }
    if (!targets) throw new Error('Chrome did not start. Set CHROME_BIN to its path.');

    const socket = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });

    let nextId = 0;
    const waiting = new Map();
    socket.onmessage = ({ data }) => {
        const message = JSON.parse(data);
        if (message.id && waiting.has(message.id)) {
            const { resolve, reject } = waiting.get(message.id);
            waiting.delete(message.id);
            if (message.error) {
                reject(new Error(message.error.message));
            } else {
                resolve(message.result);
            }
        }
    };
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const id = ++nextId;
        waiting.set(id, { resolve, reject });
        socket.send(JSON.stringify({ id, method, params }));
    });

    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.enable');
    await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });

    const page = {
        send,
        /** Run JavaScript in the page and get its (JSON-able) result back. */
        async evaluate(expression) {
            const { result, exceptionDetails } = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
            if (exceptionDetails) throw new Error(exceptionDetails.exception?.description || exceptionDetails.text);
            return result.value;
        },
        async goto(url, settle = 1300) {
            await send('Page.navigate', { url });
            await sleep(settle);
        },
        async reload(settle = 1300) {
            await send('Page.reload');
            await sleep(settle);
        },
        /** Pretend the computer is set to light or dark, as the "System" option reads it. */
        async systemTheme(scheme) {
            await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: scheme }] });
            await sleep(350);
        },
        async cookie(name, value, url) {
            await send('Network.setCookie', { name, value, url });
        },
        /** Click the first visible element matching the selector, optionally with the given text. */
        async click(selector, text = null, settle = 500) {
            const clicked = await page.evaluate(`(() => {
                const wanted = ${JSON.stringify(text)};
                const el = [...document.querySelectorAll(${JSON.stringify(selector)})].find((node) => {
                    const box = node.getBoundingClientRect();
                    return box.width > 0 && box.height > 0 && (!wanted || node.textContent.trim().includes(wanted));
                });
                if (!el) return false;
                el.scrollIntoView({ block: 'center' });
                const box = el.getBoundingClientRect();
                const at = { bubbles: true, cancelable: true, composed: true, button: 0, clientX: box.x + box.width / 2, clientY: box.y + box.height / 2, view: window };
                // The same sequence a real mouse press produces; menus open on the first of these
                el.dispatchEvent(new PointerEvent('pointerdown', { ...at, pointerType: 'mouse', isPrimary: true, buttons: 1 }));
                el.dispatchEvent(new MouseEvent('mousedown', { ...at, buttons: 1 }));
                el.dispatchEvent(new PointerEvent('pointerup', { ...at, pointerType: 'mouse', isPrimary: true }));
                el.dispatchEvent(new MouseEvent('mouseup', at));
                el.click();
                return true;
            })()`);
            if (clicked) await sleep(settle);
            return clicked;
        },
        /** Open a menu or dropdown from the keyboard, as someone tabbing through the page would. */
        async openMenu(selector, text = null, settle = 600) {
            const found = await page.evaluate(`(() => {
                const wanted = ${JSON.stringify(text)};
                const el = [...document.querySelectorAll(${JSON.stringify(selector)})].find((node) => {
                    const box = node.getBoundingClientRect();
                    return box.width > 0 && box.height > 0 && (!wanted || node.textContent.trim().includes(wanted));
                });
                if (!el) return false;
                el.scrollIntoView({ block: 'center' });
                el.focus();
                return true;
            })()`);
            if (!found) return false;
            await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'ArrowDown', code: 'ArrowDown', windowsVirtualKeyCode: 40 });
            await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'ArrowDown', code: 'ArrowDown', windowsVirtualKeyCode: 40 });
            await sleep(settle);
            return true;
        },
        async type(selector, value) {
            await page.evaluate(`document.querySelector(${JSON.stringify(selector)}).focus()`);
            await send('Input.insertText', { text: value });
        },
        async press(key) {
            for (const type of ['keyDown', 'keyUp']) await send('Input.dispatchKeyEvent', { type, key, code: key, windowsVirtualKeyCode: key === 'Escape' ? 27 : 13 });
            await sleep(300);
        },
        async screenshot(file, fullPage = true) {
            const { data } = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: fullPage });
            writeFileSync(file, Buffer.from(data, 'base64'));
        },
        /** Log in through the real login form. */
        async login(baseUrl, email, password) {
            await page.goto(`${baseUrl}/login`);
            await page.type('input[type="email"]', email);
            await page.type('input[type="password"]', password);
            await page.click('button[type="submit"]', null, 2000);
            const path = await page.evaluate('location.pathname');
            if (path === '/login') throw new Error(`Could not log in as ${email}`);
        },
        async close() {
            socket.close();
            chrome.kill();
            await sleep(300);
            rmSync(profile, { recursive: true, force: true });
        },
    };

    return page;
}
