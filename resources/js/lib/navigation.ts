import { router } from '@inertiajs/react';

// The first "navigate" event is the page the browser loaded; anything after
// that means the user arrived at the current page from another page in the app.
let navigations = 0;

router.on('navigate', () => {
    navigations += 1;
});

/**
 * Go back to the page the user came from. When they opened this page directly
 * (a link in an email, a bookmark, a refresh) there is nothing to go back to,
 * so go to the fallback page instead.
 */
export function goBack(fallback: string): void {
    if (navigations > 1) {
        window.history.back();
    } else {
        router.get(fallback);
    }
}

// The page last opened from a notification, so that opening another notification from it
// swaps the page instead of piling up entries in the browser's Back history
let notificationPage: string | null = null;

/**
 * Open a notification's page. From a page that was itself opened from a notification
 * (or the same page again), the new one takes its place in the browser history, so
 * Back and the page's own close button still return to where the user started.
 */
export function openNotificationPage(url: string): void {
    const current = window.location.pathname + window.location.search;
    const target = new URL(url, window.location.origin);

    router.visit(target.pathname + target.search, {
        replace:
            current === notificationPage ||
            current === target.pathname + target.search,
        onSuccess: () => {
            notificationPage =
                window.location.pathname + window.location.search;
        },
    });
}
