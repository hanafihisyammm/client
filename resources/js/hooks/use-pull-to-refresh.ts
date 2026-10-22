import { useEffect, useRef, useState } from 'react';

/** How far (in px) the page has to be pulled before letting go refreshes it */
export const PULL_THRESHOLD = 70;
const MAX_PULL = 110;
// The page moves half as far as the finger, so the pull feels like stretching
const RESISTANCE = 0.5;

/**
 * Pull down from the top of the page on a touch screen to refresh, as phone apps do.
 * The installed app has no browser pull-to-refresh of its own, and the browser's one
 * reloads the whole page, so this one is used everywhere instead.
 *
 * Returns how far the page is pulled right now, to draw the indicator with.
 */
export function usePullToRefresh(onRefresh: () => unknown): number {
    const [pull, setPull] = useState(0);
    const onRefreshRef = useRef(onRefresh);

    useEffect(() => {
        onRefreshRef.current = onRefresh;
    }, [onRefresh]);

    useEffect(() => {
        let startX = 0;
        let startY = 0;
        // null until the finger has moved enough to tell a pull from a sideways swipe or a scroll
        let pulling: boolean | null = null;
        let distance = 0;

        const reset = () => {
            pulling = null;
            distance = 0;
            setPull(0);
        };

        const onStart = (event: TouchEvent) => {
            const target = event.target as Element | null;
            // Leave open menus and dialogs, which scroll on their own, alone
            const inOverlay = target?.closest('[role="dialog"], [role="menu"], [role="listbox"], [data-radix-popper-content-wrapper]');

            if (event.touches.length !== 1 || window.scrollY > 0 || inOverlay) {
                pulling = false;
                return;
            }

            startX = event.touches[0].clientX;
            startY = event.touches[0].clientY;
            pulling = null;
            distance = 0;
        };

        const onMove = (event: TouchEvent) => {
            if (pulling === false) return;

            const dx = event.touches[0].clientX - startX;
            const dy = event.touches[0].clientY - startY;

            if (pulling === null) {
                if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return;
                // Sideways (the board's columns) or upwards is an ordinary scroll
                pulling = dy > 0 && Math.abs(dy) > Math.abs(dx) && window.scrollY <= 0;
                if (!pulling) return;
            }

            distance = Math.min(MAX_PULL, Math.max(0, dy * RESISTANCE));
            setPull(distance);
            // Keep the browser from bouncing the page or reloading it itself
            if (event.cancelable) event.preventDefault();
        };

        const onEnd = () => {
            if (pulling && distance >= PULL_THRESHOLD) onRefreshRef.current();
            reset();
        };

        window.addEventListener('touchstart', onStart, { passive: true });
        window.addEventListener('touchmove', onMove, { passive: false });
        window.addEventListener('touchend', onEnd);
        window.addEventListener('touchcancel', reset);

        return () => {
            window.removeEventListener('touchstart', onStart);
            window.removeEventListener('touchmove', onMove);
            window.removeEventListener('touchend', onEnd);
            window.removeEventListener('touchcancel', reset);
        };
    }, []);

    return pull;
}
