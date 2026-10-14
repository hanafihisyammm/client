/**
 * Opening notifications one after another swaps the page in place instead of piling up
 * entries in the browser's Back history, and Back (the page's own button or the
 * browser's) still returns to where the user started.
 *
 * Needs the local site running from a production build with the demo data:
 *   node --test tests/browser/notification-navigation.test.mjs
 * It gives the demo user three in-app notifications (no email, no alert) and removes them again.
 */
/* global process */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { after, afterEach, before, beforeEach, test } from 'node:test';
import { launch } from './lib.mjs';

const BASE = process.env.APP_URL || 'http://127.0.0.1:8000';
const EMAIL = 'acme.staff1@example.com';
const TITLE = 'Navigation test';

let page;
let issues = [];

const php = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], { encoding: 'utf8' }).trim();
const path = () => page.evaluate('location.pathname');
const historyLength = () => page.evaluate('history.length');

/** Open the bell and click the given test notification. */
async function openNotification(number) {
    assert.ok(await page.click('button[aria-label^="Notifications"]', null, 1200), 'the notifications menu');
    assert.ok(await page.click('[role="menuitem"]', `${TITLE} ${number}`, 2000), `notification ${number} is listed`);
}

before(() => {
    // Three of the user's issues, each with an in-app notification pointing at it
    issues = JSON.parse(php(`
        $user = App\\Models\\User::where('email', '${EMAIL}')->first();
        $user->notifications()->where('data', 'like', '%"title":"${TITLE}%')->delete();
        $paths = App\\Models\\Report::visibleTo($user)->latest('id')->take(3)->get()->map(fn ($report) => '/reports/' . $report->uuid)->values();
        foreach ($paths as $index => $path) {
            $note = new class($path, $index + 1) extends App\\Notifications\\AppNotification {
                public function __construct(private string $path, private int $number) {}
                public function toMail($notifiable) { return new Illuminate\\Notifications\\Messages\\MailMessage; }
                public function toArray($notifiable) { return ['title' => '${TITLE} ' . $this->number, 'message' => 'Opens ' . $this->path, 'url' => $this->path]; }
            };
            Illuminate\\Support\\Facades\\Notification::sendNow($user, $note, ['database']);
            usleep(1100000); // listed newest first: keep them in order
        }
        echo json_encode($paths);
    `).split('\n').pop());
    assert.equal(issues.length, 3, 'the demo user has three issues to open');
});

beforeEach(async () => {
    page = await launch();
    await page.login(BASE, EMAIL, 'password');
    assert.equal(await path(), '/dashboard');
});

// Closed after every test, passed or not, so a failure never leaves a browser running
afterEach(async () => {
    await page?.close();
    page = null;
});

after(() => {
    php(`App\\Models\\User::where('email', '${EMAIL}')->first()->notifications()->where('data', 'like', '%"title":"${TITLE}%')->delete();`);
});

test('opening notification after notification replaces the page instead of adding history', async () => {
    const start = await historyLength();

    await openNotification(1);
    assert.equal(await path(), issues[0]);
    assert.equal(await historyLength(), start + 1, 'the first one is a normal step forward');

    await openNotification(2);
    assert.equal(await path(), issues[1]);
    assert.equal(await historyLength(), start + 1, 'the second replaces the first');

    await openNotification(3);
    assert.equal(await path(), issues[2]);
    assert.equal(await historyLength(), start + 1, 'and so does the third');

    // Opening the one already shown adds nothing either
    await openNotification(3);
    assert.equal(await path(), issues[2]);
    assert.equal(await historyLength(), start + 1);
});

test("the page's close button returns to where the user started", async () => {
    await openNotification(1);
    await openNotification(2);

    assert.ok(await page.click('button', 'Back', 1500), 'the Back button on the issue page');
    assert.equal(await path(), '/dashboard');
});

test("the browser's Back button returns to where the user started, not to the earlier notification", async () => {
    await openNotification(1);
    await openNotification(2);
    await openNotification(3);

    await page.evaluate('history.back()');
    await new Promise((resolve) => setTimeout(resolve, 1500));

    assert.equal(await path(), '/dashboard');
});

test('an ordinary link after a notification still adds history as usual', async () => {
    const start = await historyLength();

    await openNotification(1);
    assert.ok(await page.click('a[href="/projects"]', null, 1500), 'a sidebar link');
    assert.equal(await path(), '/projects');
    assert.equal(await historyLength(), start + 2);

    // A notification opened from that ordinary page is a normal step forward again
    await openNotification(2);
    assert.equal(await path(), issues[1]);
    assert.equal(await historyLength(), start + 3);
});
