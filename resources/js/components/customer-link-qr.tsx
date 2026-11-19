import { router } from '@inertiajs/react';
import { QrCode, RefreshCw, X } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import JoinController from '@/actions/App/Http/Controllers/Customer/JoinController';
import { Button } from '@/components/ui/button';
import { useLiveReload } from '@/hooks/use-live-reload';

export type CustomerLink = {
    payload: string;
    /** Seconds left, as of the server's answer; 0 once used or expired. */
    expires_in: number;
    /** The table staff opened with it, once used. */
    table_code: string | null;
} | null;

/** Lifetime of a code on the server (CustomerLink::TTL_SECONDS). */
const TTL_SECONDS = 60;

/**
 * The customer's one-time QR for staff opening a table: valid one minute, works
 * once. A border runs out around it and a fresh code replaces it when it does.
 * When staff use it, the customer's screen follows them to the new table.
 */
export function CustomerLinkQr({ link }: { link: CustomerLink }) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);
    // Only a QR shown on this screen sends the customer to its table, not an
    // old one used before they opened the page.
    const shownPayload = useRef<string | null>(null);
    const active =
        link !== null && link.table_code === null && link.expires_in > 0;

    useLiveReload({
        only: ['link', 'tables'],
        intervalMs: 2_000,
        enabled: active,
    });

    useEffect(() => {
        if (active) {
            shownPayload.current = link.payload;
        }
    }, [active, link]);

    useEffect(() => {
        if (link?.table_code && shownPayload.current === link.payload) {
            router.visit(JoinController.show.url(link.table_code));
        }
    }, [link]);

    function post(url: string) {
        if (busy) {
            return;
        }

        setBusy(true);
        router.post(
            url,
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    }

    const generate = () => post(JoinController.createLink.url());
    const cancel = () => post(JoinController.cancelLink.url());

    // A new code replaces the expired one, but not while nobody is looking:
    // a forgotten tab waits until it's visible again.
    function renew() {
        if (document.visibilityState === 'visible') {
            generate();

            return;
        }

        const onVisible = () => {
            if (document.visibilityState === 'visible') {
                document.removeEventListener('visibilitychange', onVisible);
                generate();
            }
        };

        document.addEventListener('visibilitychange', onVisible);
    }

    return (
        <section className="flex w-full max-w-sm flex-col items-center gap-4 rounded-xl border border-border bg-card p-5 text-center">
            <div className="space-y-1">
                <h2 className="text-lg font-bold">
                    {t('join_table.link_title')}
                </h2>
                <p className="text-sm text-muted-foreground">
                    {t('join_table.link_description')}
                </p>
            </div>

            {active ? (
                <>
                    <ExpiryRing
                        key={link.payload}
                        seconds={link.expires_in}
                        onExpire={renew}
                    >
                        <QRCodeSVG value={link.payload} size={200} />
                    </ExpiryRing>
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={busy}
                        onClick={cancel}
                    >
                        <X />
                        {t('join_table.link_cancel')}
                    </Button>
                </>
            ) : (
                <Button onClick={generate} disabled={busy} size="lg">
                    {link === null ? <QrCode /> : <RefreshCw />}
                    {link === null
                        ? t('join_table.link_generate')
                        : t('join_table.link_generate_new')}
                </Button>
            )}
        </section>
    );
}

const RING_SIZE = 248;
const RING_STROKE = 6;
const RING_RADIUS = 18;
const TICK_MS = 200;

/** Rounded square traced clockwise from the top center. */
function ringPath(): string {
    const lo = RING_STROKE / 2;
    const hi = RING_SIZE - RING_STROKE / 2;
    const r = RING_RADIUS;
    const mid = RING_SIZE / 2;

    return [
        `M ${mid} ${lo}`,
        `H ${hi - r}`,
        `A ${r} ${r} 0 0 1 ${hi} ${lo + r}`,
        `V ${hi - r}`,
        `A ${r} ${r} 0 0 1 ${hi - r} ${hi}`,
        `H ${lo + r}`,
        `A ${r} ${r} 0 0 1 ${lo} ${hi - r}`,
        `V ${lo + r}`,
        `A ${r} ${r} 0 0 1 ${lo + r} ${lo}`,
        'Z',
    ].join(' ');
}

const RING_PATH = ringPath();

function ringColor(fraction: number): string {
    if (fraction > 0.5) {
        return '#22B573';
    }

    return fraction > 0.2 ? '#F59E0B' : '#EF4444';
}

/** A border that shrinks clockwise as the code's time runs out. */
function ExpiryRing({
    seconds,
    onExpire,
    children,
}: {
    seconds: number;
    onExpire: () => void;
    children: ReactNode;
}) {
    const { t } = useTranslation();
    const [deadline] = useState(() => Date.now() + seconds * 1000);
    const [leftMs, setLeftMs] = useState(seconds * 1000);
    const onExpireRef = useRef(onExpire);

    useEffect(() => {
        onExpireRef.current = onExpire;
    }, [onExpire]);

    useEffect(() => {
        const timer = window.setInterval(() => {
            const left = Math.max(0, deadline - Date.now());

            setLeftMs(left);

            if (left === 0) {
                window.clearInterval(timer);
                onExpireRef.current();
            }
        }, TICK_MS);

        return () => window.clearInterval(timer);
    }, [deadline]);

    const fraction = Math.min(1, leftMs / (TTL_SECONDS * 1000));

    return (
        <div
            className="relative flex items-center justify-center"
            style={{ width: RING_SIZE, height: RING_SIZE }}
            role="timer"
            aria-label={t('join_table.link_expires_in', {
                count: Math.ceil(leftMs / 1000),
            })}
        >
            <svg
                className="absolute inset-0"
                width={RING_SIZE}
                height={RING_SIZE}
                viewBox={`0 0 ${RING_SIZE} ${RING_SIZE}`}
                aria-hidden="true"
            >
                <path
                    d={RING_PATH}
                    fill="none"
                    className="stroke-border"
                    strokeWidth={RING_STROKE}
                />
                <path
                    d={RING_PATH}
                    fill="none"
                    pathLength={100}
                    strokeDasharray="100 100"
                    // Negative offset eats the stroke from its start, so the gap advances clockwise.
                    strokeDashoffset={-100 * (1 - fraction)}
                    stroke={ringColor(fraction)}
                    strokeWidth={RING_STROKE}
                    strokeLinecap="round"
                    style={{
                        transition: `stroke-dashoffset ${TICK_MS}ms linear, stroke 400ms ease`,
                    }}
                />
            </svg>
            <div className="rounded-lg bg-white p-2">{children}</div>
        </div>
    );
}
