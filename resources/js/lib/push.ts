/**
 * Phone / desktop alerts (Web Push) for this browser. Turning them on asks the browser for
 * permission, subscribes through the service worker and tells the server where to send them.
 */
import { isIos, isStandalone } from './pwa';

export type PushStatus =
    /** This browser cannot show alerts (or the service worker is not running) */
    | 'unavailable'
    /** iPhone / iPad: only once the app has been added to the Home Screen */
    | 'needs-install'
    /** The user blocked alerts for this site in the browser */
    | 'blocked'
    | 'off'
    | 'on';

const browserSupportsPush = () =>
    'serviceWorker' in navigator &&
    'PushManager' in window &&
    'Notification' in window;

async function registration(): Promise<ServiceWorkerRegistration | null> {
    if (!('serviceWorker' in navigator)) return null;

    return (await navigator.serviceWorker.getRegistration()) ?? null;
}

export async function pushStatus(): Promise<PushStatus> {
    if (!browserSupportsPush()) {
        return isIos() && !isStandalone() ? 'needs-install' : 'unavailable';
    }

    const worker = await registration();
    if (!worker) return 'unavailable';
    if (Notification.permission === 'denied') return 'blocked';

    const subscription = await worker.pushManager.getSubscription();

    return subscription && Notification.permission === 'granted' ? 'on' : 'off';
}

/**
 * Turn alerts on. Must be called from a click: browsers only ask for permission then.
 */
export async function enablePush(publicKey: string): Promise<PushStatus> {
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return pushStatus();

    const worker = await navigator.serviceWorker.ready;
    await save(await subscribe(worker, publicKey));

    return 'on';
}

export async function disablePush(): Promise<PushStatus> {
    const endpoint = await unsubscribe();

    if (endpoint) {
        await fetch('/api/push-subscriptions', {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ endpoint }),
        });
    }

    return pushStatus();
}

/**
 * When the app opens: if alerts are on in this browser, make sure the server sends them to
 * the user who is signed in now, with the current key.
 */
export async function syncPush(publicKey: string): Promise<void> {
    if (!browserSupportsPush() || Notification.permission !== 'granted') return;

    const worker = await registration();
    if (!worker || !(await worker.pushManager.getSubscription())) return;

    await save(await subscribe(worker, publicKey)).catch(() => {
        // Tried again the next time the app opens
    });
}

/**
 * Logging out: stop this browser's alerts. Returns its address so the server can forget it.
 */
export async function unsubscribe(): Promise<string | null> {
    if (!browserSupportsPush()) return null;

    const subscription = await (
        await registration()
    )?.pushManager.getSubscription();
    if (!subscription) return null;

    await subscription.unsubscribe().catch(() => false);

    return subscription.endpoint;
}

/** The unread count on the installed app's icon, where the system shows one. */
export function showUnreadOnAppIcon(count: number): void {
    const badge = navigator as Navigator & {
        setAppBadge?: (count: number) => Promise<void>;
        clearAppBadge?: () => Promise<void>;
    };

    (count > 0 ? badge.setAppBadge?.(count) : badge.clearAppBadge?.())?.catch(
        () => undefined,
    );
}

async function subscribe(
    worker: ServiceWorkerRegistration,
    publicKey: string,
): Promise<PushSubscription> {
    const key = keyBytes(publicKey);
    const existing = await worker.pushManager.getSubscription();

    if (existing) {
        if (sameKey(existing.options.applicationServerKey, key))
            return existing;
        // The site's key has changed since this browser subscribed
        await existing.unsubscribe();
    }

    return worker.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: key,
    });
}

async function save(subscription: PushSubscription): Promise<void> {
    const encodings = (
        PushManager as unknown as { supportedContentEncodings?: string[] }
    ).supportedContentEncodings ?? ['aesgcm'];

    const response = await fetch('/api/push-subscriptions', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: JSON.stringify({
            ...subscription.toJSON(),
            contentEncoding: encodings.includes('aes128gcm')
                ? 'aes128gcm'
                : 'aesgcm',
        }),
    });

    if (!response.ok)
        throw new Error(`Could not save the subscription (${response.status})`);
}

function keyBytes(base64Url: string): Uint8Array<ArrayBuffer> {
    const base64 = (base64Url + '='.repeat((4 - (base64Url.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const raw = atob(base64);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));
    for (let index = 0; index < raw.length; index++)
        bytes[index] = raw.charCodeAt(index);

    return bytes;
}

function sameKey(current: ArrayBuffer | null, wanted: Uint8Array): boolean {
    if (!current || current.byteLength !== wanted.byteLength) return false;
    const bytes = new Uint8Array(current);

    return bytes.every((byte, index) => byte === wanted[index]);
}
