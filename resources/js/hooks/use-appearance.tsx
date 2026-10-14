import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

const APPEARANCES: Appearance[] = ['light', 'dark', 'system'];
const CHANGE_EVENT = 'appearance-change';

const isAppearance = (value: unknown): value is Appearance => APPEARANCES.includes(value as Appearance);

const systemQuery = () => (typeof window === 'undefined' ? null : window.matchMedia('(prefers-color-scheme: dark)'));

/**
 * Whether the given choice means dark colours right now.
 * "system" follows the computer or browser setting.
 */
export const resolvesToDark = (appearance: Appearance, systemPrefersDark: boolean) =>
    appearance === 'dark' || (appearance === 'system' && systemPrefersDark);

/**
 * The current choice. The server writes it on <html data-appearance> from the user's saved
 * setting (or, when logged out, from this browser's cookie), so there is one source of truth.
 */
export const currentAppearance = (): Appearance => {
    const value = typeof document === 'undefined' ? null : document.documentElement.dataset.appearance;

    return isAppearance(value) ? value : 'system';
};

const applyTheme = (appearance: Appearance) => {
    const root = document.documentElement;
    const isDark = resolvesToDark(appearance, systemQuery()?.matches ?? false);

    root.dataset.appearance = appearance;
    root.classList.toggle('dark', isDark);
    // Also switches the browser's own controls: scrollbars, date pickers, autofill
    root.style.colorScheme = isDark ? 'dark' : 'light';
    // The installed app's title bar and the mobile browser's toolbar
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', isDark ? '#0e1116' : '#f7f7f4');

    window.dispatchEvent(new CustomEvent(CHANGE_EVENT, { detail: appearance }));
};

const setCookie = (name: string, value: string, days = 365) => {
    document.cookie = `${name}=${value};path=/;max-age=${days * 24 * 60 * 60};SameSite=Lax`;
};

/**
 * Called once when the app starts.
 */
export function initializeTheme() {
    applyTheme(currentAppearance());

    // While set to "system", follow the computer when it switches between light and dark
    systemQuery()?.addEventListener('change', () => applyTheme(currentAppearance()));

    // Every page the server sends says which theme this user has saved. Logging in happens
    // without a full page load, so this is what brings a user's own theme in at that moment.
    router.on('navigate', (event) => {
        const saved = (event.detail.page.props as { appearance?: unknown }).appearance;

        if (isAppearance(saved) && saved !== currentAppearance()) {
            applyTheme(saved);
        }
    });
}

export function useAppearance() {
    const [appearance, setAppearance] = useState<Appearance>(currentAppearance);

    // Stay in step when the theme is changed elsewhere on the page
    useEffect(() => {
        const sync = (event: Event) => setAppearance((event as CustomEvent<Appearance>).detail);

        window.addEventListener(CHANGE_EVENT, sync);

        return () => window.removeEventListener(CHANGE_EVENT, sync);
    }, []);

    const updateAppearance = useCallback((mode: Appearance) => {
        // Show it at once, and remember it in this browser so a refresh starts in the right colours
        applyTheme(mode);
        setCookie('appearance', mode);

        // Save it on the account, so it is there on the next login from any browser
        router.patch('/settings/appearance', { appearance: mode }, { preserveScroll: true, preserveState: true });
    }, []);

    return { appearance, updateAppearance } as const;
}
