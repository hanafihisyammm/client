/**
 * Installing Sidra Desk as an app, and knowing whether there is a connection.
 * Kept outside React because the browser's install offer can arrive before any page is drawn.
 */

interface BeforeInstallPromptEvent extends Event {
    prompt(): Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

export interface InstallState {
    /** The browser has offered to install the app (Chrome, Edge, Samsung Internet ...) */
    canPrompt: boolean;
    /** iPhone and iPad Safari never offer it; the app is added from the Share menu instead */
    needsManualSteps: boolean;
    /** Already running as the installed app */
    installed: boolean;
}

let installPrompt: BeforeInstallPromptEvent | null = null;
let state: InstallState = {
    canPrompt: false,
    needsManualSteps: false,
    installed: false,
};
const listeners = new Set<() => void>();

export const isStandalone = () =>
    window.matchMedia('(display-mode: standalone)').matches ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true;

export const isIos = () =>
    /iphone|ipad|ipod/i.test(navigator.userAgent) ||
    // iPadOS reports itself as a Mac
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

function update() {
    const installed = isStandalone();

    state = {
        canPrompt: !installed && installPrompt !== null,
        needsManualSteps: !installed && installPrompt === null && isIos(),
        installed,
    };
    listeners.forEach((listener) => listener());
}

/**
 * Called once when the app starts.
 */
export function initializePwa() {
    window.addEventListener('beforeinstallprompt', (event) => {
        // Offer it from the app's own button instead of the browser's pop-up
        event.preventDefault();
        installPrompt = event as BeforeInstallPromptEvent;
        update();
    });

    window.addEventListener('appinstalled', () => {
        installPrompt = null;
        update();
    });

    update();

    // Only built files are served from /build; during development Vite serves them itself
    if (import.meta.env.PROD && 'serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js').catch(() => {
                // The site works the same without it; it just cannot be installed
            });
        });
    }
}

export function subscribeToInstallState(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

export const getInstallState = (): InstallState => state;

/**
 * Show the browser's own install window. Resolves to true if the app was installed.
 */
export async function promptInstall(): Promise<boolean> {
    if (!installPrompt) return false;

    const prompt = installPrompt;
    // The browser lets each offer be used once
    installPrompt = null;
    await prompt.prompt();
    const { outcome } = await prompt.userChoice;
    update();

    return outcome === 'accepted';
}

export function subscribeToConnection(listener: () => void): () => void {
    window.addEventListener('online', listener);
    window.addEventListener('offline', listener);

    return () => {
        window.removeEventListener('online', listener);
        window.removeEventListener('offline', listener);
    };
}

export const isOnline = (): boolean => navigator.onLine;
