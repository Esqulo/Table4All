import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';

/**
 * Illustrative tab used on the welcome page. It fills itself in as the
 * visitor scrolls through the steps (stage 0 = hero, 5 = table closed).
 * All values are synthetic and labelled as an example.
 */

type QueueKey = 'bar' | 'kitchen';
type Status = 'queued' | 'ready' | 'delivered';

type Item = {
    id: 'chopp' | 'pastel' | 'caipirinha' | 'mandioca';
    qty: number;
    unit: number;
    who: string;
    queue: QueueKey;
    from: number;
    promoWas?: number;
};

type Payment = {
    method: 'pix' | 'card' | 'cash';
    who: string;
    amount: number;
    from: number;
};

export const RECEIPT_LAST_STAGE = 5;

const ITEMS: Item[] = [
    {
        id: 'chopp',
        qty: 2,
        unit: 9.9,
        who: 'Ana',
        queue: 'bar',
        from: 0,
        promoWas: 12.9,
    },
    { id: 'pastel', qty: 1, unit: 32, who: 'Bruno', queue: 'kitchen', from: 0 },
    { id: 'caipirinha', qty: 1, unit: 24, who: 'Carla', queue: 'bar', from: 1 },
    { id: 'mandioca', qty: 1, unit: 29, who: 'Ana', queue: 'kitchen', from: 1 },
];

const PAYMENTS: Payment[] = [
    { method: 'pix', who: 'Ana', amount: 40, from: 4 },
    { method: 'card', who: 'Bruno', amount: 38, from: 4 },
    { method: 'cash', who: 'Carla', amount: 26.8, from: 5 },
];

const MID_SERVICE: Record<Item['id'], Status> = {
    chopp: 'delivered',
    pastel: 'delivered',
    caipirinha: 'ready',
    mandioca: 'queued',
};

function statusAt(stage: number, id: Item['id']): Status | null {
    if (stage < 2) {
        return null;
    }

    return stage >= 4 ? 'delivered' : MID_SERVICE[id];
}

export function Receipt({
    stage,
    animate,
}: {
    stage: number;
    animate: boolean;
}) {
    const { t, i18n } = useTranslation();
    const money = new Intl.NumberFormat(
        i18n.language === 'en' ? 'en-US' : 'pt-BR',
        {
            style: 'currency',
            currency: 'BRL',
        },
    );

    const items = ITEMS.filter((item) => item.from <= stage);
    const payments = PAYMENTS.filter((p) => p.from <= stage);
    const total = items.reduce((sum, item) => sum + item.qty * item.unit, 0);
    const paid = payments.reduce((sum, p) => sum + p.amount, 0);
    const remaining = Math.max(0, Math.round((total - paid) * 100) / 100);
    const closed = stage >= RECEIPT_LAST_STAGE;
    const enter = animate ? 't4a-line-enter' : undefined;

    // Grow the paper smoothly when lines are added: measure the content and
    // transition the paper's height (the torn edge is masked to its bottom).
    const contentRef = useRef<HTMLDivElement>(null);
    const [paperHeight, setPaperHeight] = useState<number | null>(null);

    useEffect(() => {
        const content = contentRef.current;

        if (!content) {
            return;
        }

        const observer = new ResizeObserver(() =>
            setPaperHeight(content.offsetHeight),
        );
        observer.observe(content);

        return () => observer.disconnect();
    }, []);

    return (
        <figure
            aria-label={t('welcome.receipt.label')}
            className="relative drop-shadow-[0_18px_28px_oklch(0.25_0.04_155/0.22)]"
        >
            <div
                className="t4a-receipt overflow-hidden bg-[var(--w-paper)] transition-[height] duration-500 ease-[cubic-bezier(0.16,1,0.3,1)] motion-reduce:transition-none"
                style={
                    paperHeight === null ? undefined : { height: paperHeight }
                }
            >
                <div
                    ref={contentRef}
                    className="px-6 pt-6 pb-10 font-receipt text-[13px] leading-relaxed text-[var(--w-paper-ink)] tabular-nums sm:px-7"
                >
                    <header className="border-b border-dashed border-[var(--w-paper-rule)] pb-4 text-center">
                        <p className="text-[11px] tracking-[0.18em] text-[var(--w-paper-soft)] uppercase">
                            Table4All · {t('welcome.receipt.brand')}
                        </p>
                        <p className="mt-1 font-display text-[2rem] leading-none font-extrabold tracking-tight uppercase">
                            {t('welcome.receipt.table')}
                        </p>
                        <p className="mt-2 text-[12px] text-[var(--w-paper-soft)]">
                            {closed
                                ? t('welcome.receipt.closed')
                                : t('welcome.receipt.opened')}
                        </p>
                    </header>

                    <ul className="space-y-3 border-b border-dashed border-[var(--w-paper-rule)] py-4">
                        {items.map((item) => {
                            const status = statusAt(stage, item.id);
                            const showPromo =
                                stage >= 3 && item.promoWas !== undefined;

                            return (
                                <li key={item.id} className={enter}>
                                    <div className="flex items-baseline justify-between gap-3">
                                        <span className="min-w-0">
                                            {item.qty}×{' '}
                                            {t(
                                                `welcome.receipt.items.${item.id}`,
                                            )}
                                        </span>
                                        <span className="shrink-0">
                                            {money.format(item.qty * item.unit)}
                                        </span>
                                    </div>
                                    <p className="text-[11.5px] text-[var(--w-paper-soft)]">
                                        {t('welcome.receipt.via_qr', {
                                            name: item.who,
                                        })}
                                        {status && (
                                            <span
                                                key={status}
                                                className={enter}
                                            >
                                                {' · '}
                                                {t(
                                                    `welcome.receipt.queue.${item.queue}`,
                                                )}{' '}
                                                <span
                                                    className={cn(
                                                        status ===
                                                            'delivered' &&
                                                            'text-[var(--w-paper-green)]',
                                                        status === 'ready' &&
                                                            'font-medium text-[var(--w-paper-ink)]',
                                                    )}
                                                >
                                                    {t(
                                                        `welcome.receipt.status.${status}`,
                                                    )}
                                                </span>
                                            </span>
                                        )}
                                    </p>
                                    {showPromo && (
                                        <p
                                            className={cn(
                                                'mt-1 inline-block rounded-sm bg-[oklch(0.93_0.08_85)] px-1.5 text-[11.5px] text-[oklch(0.36_0.07_70)]',
                                                enter,
                                            )}
                                        >
                                            {t('welcome.receipt.promo', {
                                                price: money.format(
                                                    item.promoWas!,
                                                ),
                                            })}
                                        </p>
                                    )}
                                </li>
                            );
                        })}
                    </ul>

                    <div className="flex items-baseline justify-between pt-4 text-[14px] font-medium">
                        <span>{t('welcome.receipt.subtotal')}</span>
                        <span>{money.format(total)}</span>
                    </div>

                    {payments.length > 0 && (
                        <div className="relative mt-4 border-t border-dashed border-[var(--w-paper-rule)] pt-4">
                            <p className="mb-2 text-[11px] tracking-[0.18em] text-[var(--w-paper-soft)] uppercase">
                                {t('welcome.receipt.paid_label')}
                            </p>
                            <ul className="space-y-1.5">
                                {payments.map((p) => (
                                    <li
                                        key={p.method}
                                        className={cn(
                                            'flex justify-between gap-3',
                                            enter,
                                        )}
                                    >
                                        <span>
                                            {t(
                                                `welcome.receipt.methods.${p.method}`,
                                            )}{' '}
                                            · {p.who}
                                        </span>
                                        <span>−{money.format(p.amount)}</span>
                                    </li>
                                ))}
                            </ul>
                            <div className="mt-3 flex items-baseline justify-between text-[15px] font-medium">
                                <span>{t('welcome.receipt.remaining')}</span>
                                <span
                                    className={cn(
                                        remaining === 0 &&
                                            'text-[var(--w-paper-green)]',
                                    )}
                                >
                                    {money.format(remaining)}
                                </span>
                            </div>

                            {closed && (
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'pointer-events-none absolute -top-3 right-1 -rotate-[9deg] rounded-md border-[3px] border-[var(--w-stamp)] px-3 pt-1 pb-0.5 font-display text-[2.6rem] leading-none font-extrabold tracking-wide text-[var(--w-stamp)] uppercase opacity-85 mix-blend-multiply',
                                        animate && 't4a-stamp-enter',
                                    )}
                                >
                                    {t('welcome.receipt.paid_stamp')}
                                </span>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </figure>
    );
}
