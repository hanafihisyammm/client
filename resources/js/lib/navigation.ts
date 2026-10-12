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
