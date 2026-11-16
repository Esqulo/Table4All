import { router } from '@inertiajs/react';
import { useEffect } from 'react';

type Options = {
    /** Page props to refresh (Inertia partial reload). Keep it to what changes. */
    only: string[];
    /** Delay between the end of one refresh and the start of the next. */
    intervalMs: number;
    /** Pause while false, e.g. while the user has unsaved edits or a dialog open. */
    enabled?: boolean;
};

// If a refresh never reports back (dropped connection), stop waiting for it.
const STALE_REQUEST_MS = 45_000;

/**
 * Keeps operational screens (kitchen queue, delivery board, tables) fresh,
 * built for slow connections and old devices:
 *
 * - Sequential: the next refresh is scheduled only after the previous one
 *   finishes, so a slow link never piles up overlapping requests (which a plain
 *   setInterval does once a response takes longer than the interval).
 * - Partial: only the listed props are requested and re-rendered.
 * - Pauses while the tab is hidden or the device is offline, and refreshes
 *   right away when it comes back, so the screen is current the moment someone
 *   looks at it.
 *
 * See docs/performance.md.
 */
export function useLiveReload({ only, intervalMs, enabled = true }: Options): void {
    const onlyKey = only.join(',');

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const props = onlyKey.split(',');
        let timer: ReturnType<typeof setTimeout> | undefined;
        let inFlightSince: number | null = null;
        let stopped = false;

        const canRun = () => !stopped && !document.hidden && navigator.onLine !== false;

        const schedule = (delay: number) => {
            clearTimeout(timer);

            if (canRun()) {
                timer = setTimeout(run, delay);
            }
        };

        const run = () => {
            if (!canRun()) {
                return;
            }

            if (inFlightSince !== null && Date.now() - inFlightSince < STALE_REQUEST_MS) {
                schedule(intervalMs);

                return;
            }

            inFlightSince = Date.now();
            router.reload({
                only: props,
                onFinish: () => {
                    inFlightSince = null;
                    schedule(intervalMs);
                },
            });
        };

        const resume = () => schedule(0);

        document.addEventListener('visibilitychange', resume);
        window.addEventListener('online', resume);
        schedule(intervalMs);

        return () => {
            stopped = true;
            clearTimeout(timer);
            document.removeEventListener('visibilitychange', resume);
            window.removeEventListener('online', resume);
        };
    }, [onlyKey, intervalMs, enabled]);
}
