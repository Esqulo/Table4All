import { router } from '@inertiajs/react';

/**
 * Pages seen in this tab since it loaded. document.referrer doesn't change on
 * Inertia visits, so this is how a "back" button knows the previous entry is
 * a page of the app, not another site or nothing at all (a link opened directly).
 */
let visits = 0;

/** Call once, from the app entry, before the first page renders. */
export function trackInAppVisits(): void {
    router.on('navigate', () => {
        visits++;
    });
}

/** Back to the previous page of the app, or to `fallback` when there is none. */
export function goBack(fallback: string): void {
    if (visits > 1) {
        window.history.back();

        return;
    }

    router.visit(fallback);
}
