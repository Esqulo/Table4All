import {
    Beer,
    Check,
    ChefHat,
    Hand,
    Heart,
    Maximize,
    Minimize,
    Pause,
    Play,
    QrCode,
    ReceiptText,
    RotateCcw,
    Star,
    Ticket,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import AppLogoIcon from '@/components/app-logo-icon';
import AppLogoWordmark from '@/components/app-logo-wordmark';
import { formatBRL } from '@/lib/money';
import { cn } from '@/lib/utils';

/**
 * The partner page's "video": an animated walkthrough drawn in the page, so it is
 * translated, sharp at any size and weighs nothing on a slow connection. Every
 * value shown is synthetic. The stage is sized in em off the player's width
 * (see .t4a-stage), so it scales like a real video.
 */

const SCENES = [
    { key: 'rush', duration: 5 },
    { key: 'qr', duration: 6.5 },
    { key: 'queue', duration: 6 },
    { key: 'call', duration: 5.5 },
    { key: 'pay', duration: 6 },
    { key: 'dash', duration: 6 },
    { key: 'loyalty', duration: 5.5 },
    { key: 'end', duration: 4 },
] as const;

type SceneKey = (typeof SCENES)[number]['key'];

const STARTS = SCENES.map((_, i) =>
    SCENES.slice(0, i).reduce((sum, scene) => sum + scene.duration, 0),
);
const TOTAL = STARTS[STARTS.length - 1] + SCENES[SCENES.length - 1].duration;

/** Clock ticks: coarse on purpose (old tablets); CSS transitions do the smoothing. */
const TICK = 0.1;

function sceneIndexAt(time: number): number {
    let index = SCENES.length - 1;

    while (index > 0 && time < STARTS[index]) {
        index--;
    }

    return index;
}

const BILL_TOTAL = 134.8;

const anim =
    'transition-[opacity,transform,background-color,border-color,color,height,width] duration-500 ease-out motion-reduce:transition-none';

const appear = (on: boolean) =>
    on ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-[0.8em]';

function useMoney() {
    const { i18n } = useTranslation();
    const locale = i18n.language === 'en' ? 'en-US' : 'pt-BR';

    return (value: number) => formatBRL(value, locale);
}

function Phone({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'relative h-[30em] w-[15.5em] shrink-0 overflow-hidden rounded-[2em] border-[0.45em] border-[oklch(0.16_0.02_155)] bg-[var(--w-paper)] text-[var(--w-paper-ink)] shadow-[0_1.5em_3em_-1em_oklch(0.1_0.03_155/0.6)]',
                className,
            )}
        >
            <div className="absolute top-[0.45em] left-1/2 z-20 h-[0.8em] w-[4.5em] -translate-x-1/2 rounded-full bg-[oklch(0.16_0.02_155)]" />
            {children}
        </div>
    );
}

function PhoneHeader({ title, sub }: { title: string; sub?: string }) {
    return (
        <div className="bg-[var(--w-paper-green)] px-[1em] pt-[2em] pb-[0.8em] text-[var(--w-on-green)]">
            <p className="text-[1.1em] leading-tight font-bold">{title}</p>
            {sub && <p className="text-[0.8em] opacity-80">{sub}</p>}
        </div>
    );
}

/* ---------- scenes ---------- */

const WAIT_ORDER = [5, 0, 10, 3, 8, 1, 11, 6, 2];

function RushScene({ t }: { t: number }) {
    const { t: tr, i18n } = useTranslation();
    const clock = new Intl.DateTimeFormat(
        i18n.language === 'en' ? 'en-US' : 'pt-BR',
        { hour: 'numeric', minute: '2-digit' },
    ).format(new Date(2026, 0, 9, 21, 4));

    return (
        <div className="absolute inset-0 flex items-center justify-center">
            <p className="absolute top-[1.4em] right-[2em] font-receipt text-[2.2em] text-[var(--w-on-green)] tabular-nums">
                {clock}
            </p>
            <div className="grid grid-cols-4 gap-x-[1.6em] gap-y-[2em]">
                {Array.from({ length: 12 }, (_, i) => {
                    const order = WAIT_ORDER.indexOf(i);
                    const waiting = order >= 0 && t >= 0.5 + order * 0.32;

                    return (
                        <div
                            key={i}
                            className={cn(
                                'relative flex h-[5.4em] w-[8.5em] flex-col items-center justify-center rounded-[0.8em] border-[0.15em] text-[var(--w-on-green)]',
                                anim,
                                waiting
                                    ? 'border-[var(--w-amber)] bg-[color-mix(in_oklch,var(--w-amber)_22%,transparent)]'
                                    : 'border-[color-mix(in_oklch,var(--w-on-green)_22%,transparent)] bg-[color-mix(in_oklch,var(--w-on-green)_5%,transparent)]',
                            )}
                        >
                            <span className="text-[1em] font-semibold">
                                {tr('partner.video.stage.table', {
                                    number: i + 1,
                                })}
                            </span>
                            <span
                                className={cn(
                                    'font-receipt text-[0.85em] text-[var(--w-amber)]',
                                    anim,
                                    waiting ? 'opacity-100' : 'opacity-0',
                                )}
                            >
                                {tr('partner.video.stage.waiting', {
                                    minutes: 4 + ((i * 7) % 13),
                                })}
                            </span>
                            <span
                                className={cn(
                                    'absolute -top-[1em] -right-[0.9em] flex size-[2.3em] items-center justify-center rounded-full bg-[var(--w-amber)] text-[oklch(0.25_0.04_70)]',
                                    anim,
                                    waiting
                                        ? 'scale-100 opacity-100'
                                        : 'scale-50 opacity-0',
                                )}
                            >
                                <Hand className="size-[1.25em]" />
                            </span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

const MENU = [
    { key: 'chopp', price: 12.9, hue: 80 },
    { key: 'pastel', price: 32, hue: 60 },
    { key: 'fries', price: 29, hue: 95 },
    { key: 'caipirinha', price: 24, hue: 130 },
] as const;

const ADDS = [
    { key: 'chopp', at: 2.3 },
    { key: 'chopp', at: 2.8 },
    { key: 'pastel', at: 3.3 },
    { key: 'caipirinha', at: 3.9 },
] as const;

function QrScene({ t }: { t: number }) {
    const { t: tr } = useTranslation();
    const money = useMoney();
    const menuOpen = t >= 1.6;
    const added = ADDS.filter((add) => t >= add.at);
    const total = added.reduce(
        (sum, add) => sum + MENU.find((m) => m.key === add.key)!.price,
        0,
    );
    const sent = t >= 4.7;

    return (
        <div className="absolute inset-0 flex items-center justify-center gap-[5em]">
            <Phone>
                {/* QR on the table */}
                <div
                    className={cn(
                        'absolute inset-0 flex flex-col items-center justify-center gap-[1em] bg-[var(--w-paper)]',
                        anim,
                        menuOpen ? 'opacity-0' : 'opacity-100',
                    )}
                >
                    <div className="relative rounded-[0.8em] border-[0.15em] border-[var(--w-paper-rule)] p-[1em]">
                        <QrCode className="size-[8em] text-[var(--w-paper-ink)]" />
                        <span className="t4a-scan absolute right-[0.4em] left-[0.4em] h-[0.2em] rounded-full bg-[var(--w-paper-green)] shadow-[0_0_0.8em_var(--w-paper-green)]" />
                    </div>
                    <p className="text-[1.1em] font-bold">
                        {tr('partner.video.stage.table', { number: 12 })}
                    </p>
                </div>

                {/* Menu */}
                <div
                    className={cn(
                        'absolute inset-0 flex flex-col',
                        anim,
                        menuOpen ? 'opacity-100' : 'opacity-0',
                    )}
                >
                    <PhoneHeader
                        title={tr('partner.video.stage.menu')}
                        sub={tr('partner.video.stage.table', { number: 12 })}
                    />
                    <ul className="flex-1 divide-y divide-[var(--w-paper-rule)] px-[0.8em]">
                        {MENU.map((item) => {
                            const count = added.filter(
                                (a) => a.key === item.key,
                            ).length;
                            const pressing = ADDS.some(
                                (a) =>
                                    a.key === item.key &&
                                    t >= a.at &&
                                    t < a.at + 0.35,
                            );

                            return (
                                <li
                                    key={item.key}
                                    className="flex items-center gap-[0.7em] py-[0.7em]"
                                >
                                    <span
                                        className="size-[3em] shrink-0 rounded-[0.5em]"
                                        style={{
                                            background: `linear-gradient(135deg, oklch(0.82 0.12 ${item.hue}), oklch(0.6 0.13 ${item.hue - 25}))`,
                                        }}
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="line-clamp-2 block text-[0.8em] leading-tight font-semibold">
                                            {tr(
                                                `partner.video.stage.items.${item.key}`,
                                            )}
                                        </span>
                                        <span className="block text-[0.8em] text-[var(--w-paper-soft)]">
                                            {money(item.price)}
                                        </span>
                                    </span>
                                    <span
                                        className={cn(
                                            'flex size-[1.9em] items-center justify-center rounded-full text-[0.9em] font-bold',
                                            anim,
                                            count > 0
                                                ? 'bg-[var(--w-paper-green)] text-[var(--w-on-green)]'
                                                : 'border-[0.12em] border-[var(--w-paper-green)] text-[var(--w-paper-green)]',
                                            pressing
                                                ? 'scale-125'
                                                : 'scale-100',
                                        )}
                                    >
                                        {count > 0 ? count : '+'}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                    <div
                        className={cn(
                            'm-[0.8em] flex items-center justify-between rounded-full px-[1.1em] py-[0.75em] text-[0.85em] font-bold text-[var(--w-on-green)]',
                            anim,
                            added.length > 0 ? 'opacity-100' : 'opacity-0',
                            t >= 4.4 && !sent
                                ? 'scale-95 bg-[var(--w-green-strong)]'
                                : 'bg-[var(--w-paper-green)]',
                        )}
                    >
                        <span>
                            {tr('partner.video.stage.order_button', {
                                count: added.length,
                            })}
                        </span>
                        <span>{money(total)}</span>
                    </div>
                </div>

                {/* Sent */}
                <div
                    className={cn(
                        'absolute inset-0 z-10 flex flex-col items-center justify-center gap-[0.8em] bg-[color-mix(in_oklch,var(--w-paper)_92%,transparent)]',
                        anim,
                        sent ? 'opacity-100' : 'pointer-events-none opacity-0',
                    )}
                >
                    <span
                        className={cn(
                            'flex size-[4.5em] items-center justify-center rounded-full bg-[var(--w-paper-green)] text-[var(--w-on-green)]',
                            anim,
                            sent ? 'scale-100' : 'scale-50',
                        )}
                    >
                        <Check className="size-[2.6em]" strokeWidth={3} />
                    </span>
                    <p className="text-[1.15em] font-bold">
                        {tr('partner.video.stage.order_sent')}
                    </p>
                </div>
            </Phone>

            <div className="flex w-[19em] flex-col gap-[1.2em]">
                {(
                    [
                        {
                            queue: 'bar',
                            at: 5.1,
                            lines: ['2× chopp', '1× caipirinha'],
                        },
                        { queue: 'kitchen', at: 5.5, lines: ['1× pastel'] },
                    ] as const
                ).map((ticket) => (
                    <div
                        key={ticket.queue}
                        className={cn(
                            'rounded-[0.7em] bg-[var(--w-paper)] p-[1em] text-[var(--w-paper-ink)] shadow-[0_1em_2em_-1em_oklch(0.1_0.03_155/0.6)]',
                            anim,
                            t >= ticket.at
                                ? 'translate-x-0 opacity-100'
                                : '-translate-x-[3em] opacity-0',
                        )}
                    >
                        <p className="flex items-center gap-[0.5em] text-[0.85em] font-bold tracking-wide text-[var(--w-paper-green)] uppercase">
                            {ticket.queue === 'bar' ? (
                                <Beer className="size-[1.2em]" />
                            ) : (
                                <ChefHat className="size-[1.2em]" />
                            )}
                            {tr(`partner.video.stage.queues.${ticket.queue}`)}
                        </p>
                        {ticket.lines.map((line) => {
                            const [qty, key] = line.split(' ');

                            return (
                                <p
                                    key={line}
                                    className="mt-[0.3em] font-receipt text-[0.95em]"
                                >
                                    {qty}{' '}
                                    {tr(`partner.video.stage.items.${key}`)}
                                </p>
                            );
                        })}
                    </div>
                ))}
            </div>
        </div>
    );
}

type Status = 'queued' | 'ready' | 'delivered';

type QueueTicket = {
    id: number;
    queue: 'bar' | 'kitchen';
    table: number;
    qty: number;
    item: string;
    at: number;
    ready?: number;
    delivered?: number;
};

const TICKETS: QueueTicket[] = [
    {
        id: 1,
        queue: 'bar',
        table: 12,
        qty: 2,
        item: 'chopp',
        at: 0.4,
        ready: 3.3,
        delivered: 4.7,
    },
    {
        id: 2,
        queue: 'kitchen',
        table: 12,
        qty: 1,
        item: 'pastel',
        at: 0.8,
        ready: 4.1,
    },
    { id: 3, queue: 'bar', table: 12, qty: 1, item: 'caipirinha', at: 1.2 },
    { id: 4, queue: 'kitchen', table: 7, qty: 1, item: 'fries', at: 1.8 },
    { id: 5, queue: 'bar', table: 3, qty: 3, item: 'chopp', at: 2.4 },
    { id: 6, queue: 'kitchen', table: 9, qty: 2, item: 'pastel', at: 3.0 },
];

function ticketStatus(
    ticket: { ready?: number; delivered?: number },
    t: number,
): Status {
    if (ticket.delivered !== undefined && t >= ticket.delivered) {
        return 'delivered';
    }

    return ticket.ready !== undefined && t >= ticket.ready ? 'ready' : 'queued';
}

function QueueScene({ t }: { t: number }) {
    const { t: tr } = useTranslation();

    return (
        <div className="absolute inset-0 flex items-center justify-center gap-[2.5em]">
            {(['bar', 'kitchen'] as const).map((queue) => {
                const tickets = TICKETS.filter((tk) => tk.queue === queue);
                const open = tickets.filter(
                    (tk) => t >= tk.at && ticketStatus(tk, t) !== 'delivered',
                ).length;

                return (
                    <div
                        key={queue}
                        className="h-[29em] w-[23em] rounded-[1em] bg-[oklch(0.2_0.03_155)] p-[1.1em] text-[var(--w-on-green)] shadow-[0_1.5em_3em_-1em_oklch(0.1_0.03_155/0.6)]"
                    >
                        <p className="mb-[1em] flex items-center gap-[0.5em] text-[1.25em] font-bold">
                            {queue === 'bar' ? (
                                <Beer className="size-[1.1em]" />
                            ) : (
                                <ChefHat className="size-[1.1em]" />
                            )}
                            {tr(`partner.video.stage.queues.${queue}`)}
                            <span className="ml-auto rounded-full bg-[color-mix(in_oklch,var(--w-on-green)_14%,transparent)] px-[0.6em] font-receipt text-[0.8em] tabular-nums">
                                {open}
                            </span>
                        </p>
                        <div className="flex flex-col gap-[0.7em]">
                            {tickets.map((tk) => {
                                const status = ticketStatus(tk, t);

                                return (
                                    <div
                                        key={tk.id}
                                        className={cn(
                                            'flex items-center justify-between rounded-[0.6em] bg-[var(--w-paper)] px-[0.9em] py-[0.7em] text-[var(--w-paper-ink)]',
                                            anim,
                                            t >= tk.at
                                                ? status === 'delivered'
                                                    ? 'translate-y-0 opacity-45'
                                                    : 'translate-y-0 opacity-100'
                                                : '-translate-y-[1em] opacity-0',
                                        )}
                                    >
                                        <span className="min-w-0">
                                            <span className="block text-[0.75em] font-semibold text-[var(--w-paper-soft)]">
                                                {tr(
                                                    'partner.video.stage.table',
                                                    {
                                                        number: tk.table,
                                                    },
                                                )}
                                            </span>
                                            <span
                                                className={cn(
                                                    'block truncate font-receipt text-[0.95em]',
                                                    status === 'delivered' &&
                                                        'line-through',
                                                )}
                                            >
                                                {tk.qty}×{' '}
                                                {tr(
                                                    `partner.video.stage.items.${tk.item}`,
                                                )}
                                            </span>
                                        </span>
                                        <span
                                            className={cn(
                                                'ml-[0.5em] shrink-0 rounded-full px-[0.7em] py-[0.2em] text-[0.75em] font-bold',
                                                anim,
                                                status === 'queued' &&
                                                    'bg-[color-mix(in_oklch,var(--w-amber)_35%,transparent)] text-[oklch(0.35_0.07_70)]',
                                                status === 'ready' &&
                                                    'bg-[var(--w-paper-green)] text-[var(--w-on-green)]',
                                                status === 'delivered' &&
                                                    'bg-[var(--w-paper-rule)] text-[var(--w-paper-soft)]',
                                            )}
                                        >
                                            {tr(
                                                `partner.video.stage.status.${status}`,
                                            )}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

const CALLS = [
    { table: 12, type: 'bill', at: 1.4, answered: 3.7 },
    { table: 7, type: 'waiter', at: 2.2 },
    { table: 4, type: 'waiter', at: 2.9 },
] as const;

function CallScene({ t }: { t: number }) {
    const { t: tr } = useTranslation();
    const money = useMoney();
    const pressed = t >= 0.9;
    const ringing = t >= 1.4 && t < 3.7;

    return (
        <div className="absolute inset-0 flex items-center justify-center gap-[7em]">
            <Phone>
                <PhoneHeader
                    title={tr('partner.video.stage.table', { number: 12 })}
                    sub={tr('partner.video.stage.people', { count: 3 })}
                />
                <div className="p-[1em]">
                    <div className="rounded-[0.7em] border-[0.12em] border-[var(--w-paper-rule)] p-[0.9em]">
                        <p className="text-[0.8em] text-[var(--w-paper-soft)]">
                            {tr('partner.video.stage.bill_total')}
                        </p>
                        <p className="font-receipt text-[1.5em] font-bold">
                            {money(BILL_TOTAL)}
                        </p>
                    </div>
                    <div className="mt-[1.2em] flex flex-col gap-[0.8em]">
                        <span className="flex items-center gap-[0.6em] rounded-[0.8em] border-[0.12em] border-[var(--w-paper-rule)] px-[1em] py-[0.9em] text-[0.95em] font-semibold">
                            <Hand className="size-[1.3em]" />
                            {tr('partner.video.stage.call_waiter')}
                        </span>
                        <span
                            className={cn(
                                'flex items-center gap-[0.6em] rounded-[0.8em] border-[0.12em] px-[1em] py-[0.9em] text-[0.95em] font-semibold',
                                anim,
                                pressed
                                    ? 'border-[var(--w-paper-green)] bg-[var(--w-paper-green)] text-[var(--w-on-green)]'
                                    : 'border-[var(--w-paper-rule)]',
                            )}
                        >
                            {pressed ? (
                                <Check className="size-[1.3em]" />
                            ) : (
                                <ReceiptText className="size-[1.3em]" />
                            )}
                            {tr('partner.video.stage.ask_bill')}
                        </span>
                    </div>
                </div>
            </Phone>

            <Phone>
                <div className="relative">
                    <PhoneHeader title={tr('partner.video.stage.calls')} />
                    {ringing && (
                        <span className="absolute top-[2.2em] right-[1em] flex size-[0.8em]">
                            <span className="absolute inline-flex size-full animate-ping rounded-full bg-[var(--w-amber)] motion-reduce:animate-none" />
                            <span className="relative inline-flex size-full rounded-full bg-[var(--w-amber)]" />
                        </span>
                    )}
                </div>
                <div className="flex flex-col gap-[0.7em] p-[0.8em]">
                    {CALLS.map((call) => {
                        const answered =
                            'answered' in call && t >= call.answered;

                        return (
                            <div
                                key={call.table}
                                className={cn(
                                    'flex items-center gap-[0.7em] rounded-[0.7em] border-[0.12em] p-[0.7em]',
                                    anim,
                                    t >= call.at
                                        ? 'translate-y-0 opacity-100'
                                        : '-translate-y-[1em] opacity-0',
                                    answered
                                        ? 'border-[var(--w-paper-rule)] opacity-55'
                                        : 'border-[var(--w-amber)] bg-[color-mix(in_oklch,var(--w-amber)_12%,transparent)]',
                                )}
                            >
                                <span
                                    className={cn(
                                        'flex size-[2.2em] shrink-0 items-center justify-center rounded-full',
                                        answered
                                            ? 'bg-[var(--w-paper-green)] text-[var(--w-on-green)]'
                                            : 'bg-[var(--w-amber)] text-[oklch(0.25_0.04_70)]',
                                    )}
                                >
                                    {answered ? (
                                        <Check className="size-[1.2em]" />
                                    ) : call.type === 'bill' ? (
                                        <ReceiptText className="size-[1.2em]" />
                                    ) : (
                                        <Hand className="size-[1.2em]" />
                                    )}
                                </span>
                                <span className="min-w-0">
                                    <span className="block text-[0.9em] font-bold">
                                        {tr('partner.video.stage.table', {
                                            number: call.table,
                                        })}
                                    </span>
                                    <span className="block truncate text-[0.75em] text-[var(--w-paper-soft)]">
                                        {answered
                                            ? tr('partner.video.stage.answered')
                                            : call.type === 'bill'
                                              ? tr(
                                                    'partner.video.stage.asked_bill',
                                                    {
                                                        amount: money(
                                                            BILL_TOTAL,
                                                        ),
                                                    },
                                                )
                                              : tr(
                                                    'partner.video.stage.called_waiter',
                                                )}
                                    </span>
                                </span>
                            </div>
                        );
                    })}
                </div>
            </Phone>
        </div>
    );
}

const BILL_LINES = [
    { qty: 2, item: 'chopp', amount: 25.8 },
    { qty: 1, item: 'pastel', amount: 32 },
    { qty: 2, item: 'caipirinha', amount: 48 },
    { qty: 1, item: 'fries', amount: 29 },
] as const;

const PAYMENTS = [
    { who: 'Ana', method: 'pix', amount: 50, at: 1.0 },
    { who: 'Bruno', method: 'card', amount: 44.8, at: 2.0 },
    { who: 'Carla', method: 'cash', amount: 40, at: 3.0 },
] as const;

function PayScene({ t }: { t: number }) {
    const { t: tr } = useTranslation();
    const money = useMoney();
    const paid = PAYMENTS.filter((p) => t >= p.at).reduce(
        (sum, p) => sum + p.amount,
        0,
    );
    const remaining = Math.max(0, Math.round((BILL_TOTAL - paid) * 100) / 100);
    const stamped = t >= 3.7;

    return (
        <div className="absolute inset-0 flex items-center justify-center gap-[5em]">
            <div className="relative w-[22em] rounded-[0.4em] bg-[var(--w-paper)] p-[1.4em] font-receipt text-[var(--w-paper-ink)] shadow-[0_1.5em_3em_-1em_oklch(0.1_0.03_155/0.6)]">
                <p className="text-center text-[1.1em] font-bold tracking-widest uppercase">
                    {tr('partner.video.stage.table', { number: 12 })}
                </p>
                <div className="mt-[0.8em] border-t border-dashed border-[var(--w-paper-rule)] pt-[0.6em]">
                    {BILL_LINES.map((line) => (
                        <p
                            key={line.item}
                            className="flex justify-between gap-[1em] text-[0.85em] leading-[1.9]"
                        >
                            <span className="truncate">
                                {line.qty}×{' '}
                                {tr(`partner.video.stage.items.${line.item}`)}
                            </span>
                            <span className="tabular-nums">
                                {money(line.amount)}
                            </span>
                        </p>
                    ))}
                </div>
                <p className="mt-[0.4em] flex justify-between border-t border-dashed border-[var(--w-paper-rule)] pt-[0.5em] text-[1em] font-bold">
                    <span>{tr('partner.video.stage.bill_total')}</span>
                    <span className="tabular-nums">{money(BILL_TOTAL)}</span>
                </p>
                <div className="mt-[0.6em] min-h-[5.4em]">
                    {PAYMENTS.map((p) => (
                        <p
                            key={p.who}
                            className={cn(
                                'flex justify-between text-[0.85em] leading-[1.9] text-[var(--w-paper-green)]',
                                anim,
                                appear(t >= p.at),
                            )}
                        >
                            <span>
                                {tr(`partner.video.stage.methods.${p.method}`)}{' '}
                                · {p.who}
                            </span>
                            <span className="tabular-nums">
                                −{money(p.amount)}
                            </span>
                        </p>
                    ))}
                </div>
                <div className="mt-[0.4em] h-[0.5em] overflow-hidden rounded-full bg-[var(--w-paper-rule)]">
                    <div
                        className={cn(
                            'h-full rounded-full bg-[var(--w-paper-green)]',
                            anim,
                        )}
                        style={{ width: `${(paid / BILL_TOTAL) * 100}%` }}
                    />
                </div>

                <span
                    className={cn(
                        'absolute top-[42%] left-1/2 -translate-x-1/2 -rotate-12 rounded-[0.3em] border-[0.25em] border-[var(--w-stamp)] px-[0.6em] font-display text-[3.4em] leading-tight font-extrabold tracking-wider text-[var(--w-stamp)] uppercase',
                        anim,
                        stamped
                            ? 'scale-100 opacity-90'
                            : 'scale-150 opacity-0',
                    )}
                >
                    {tr('partner.video.stage.paid_stamp')}
                </span>
            </div>

            <div className="w-[17em] text-[var(--w-on-green)]">
                <p className="text-[1.1em] text-[var(--w-on-green-soft)]">
                    {tr('partner.video.stage.remaining')}
                </p>
                <p
                    className={cn(
                        'font-display text-[4em] leading-none font-extrabold tabular-nums',
                        anim,
                        remaining === 0 && 'text-[oklch(0.85_0.15_157)]',
                    )}
                >
                    {money(remaining)}
                </p>
                <p
                    className={cn(
                        'mt-[1em] flex items-center gap-[0.5em] text-[1.2em] font-semibold',
                        anim,
                        appear(t >= 4.3),
                    )}
                >
                    <Check className="size-[1.2em]" strokeWidth={3} />
                    {tr('partner.video.stage.table_closed')}
                </p>
            </div>
        </div>
    );
}

const WEEK = [0.42, 0.36, 0.5, 0.55, 0.68, 0.92, 1] as const;

const TOP_SELLERS = [
    { item: 'chopp', count: 214, at: 2.2 },
    { item: 'fries', count: 61, at: 2.6 },
    { item: 'caipirinha', count: 48, at: 3.0 },
] as const;

function DashScene({ t }: { t: number }) {
    const { t: tr, i18n } = useTranslation();
    const money = useMoney();
    const locale = i18n.language === 'en' ? 'en-US' : 'pt-BR';
    const progress = Math.min(1, t / 1.8);
    const k = 1 - (1 - progress) ** 3;
    const weekday = new Intl.DateTimeFormat(locale, { weekday: 'short' });

    const kpis = [
        { key: 'revenue', value: money(4812.3 * k) },
        { key: 'tables', value: String(Math.round(37 * k)) },
        { key: 'ticket', value: money(130.06 * k) },
    ];

    return (
        <div className="absolute inset-0 flex items-center justify-center">
            <div className="relative w-[54em] rounded-[1em] bg-[var(--w-paper)] p-[1.6em] text-[var(--w-paper-ink)] shadow-[0_1.5em_3em_-1em_oklch(0.1_0.03_155/0.6)]">
                <div className="grid grid-cols-3 gap-[1em]">
                    {kpis.map((kpi) => (
                        <div
                            key={kpi.key}
                            className="rounded-[0.7em] border-[0.12em] border-[var(--w-paper-rule)] p-[0.9em]"
                        >
                            <p className="text-[0.8em] text-[var(--w-paper-soft)]">
                                {tr(`partner.video.stage.kpi.${kpi.key}`)}
                            </p>
                            <p className="mt-[0.2em] font-display text-[2.1em] leading-none font-bold tabular-nums">
                                {kpi.value}
                            </p>
                        </div>
                    ))}
                </div>
                <div className="mt-[1.4em] grid grid-cols-[1.4fr_1fr] gap-[2em]">
                    <div>
                        <div className="flex h-[12em] items-end gap-[0.8em] border-b-[0.12em] border-[var(--w-paper-rule)]">
                            {WEEK.map((h, i) => (
                                <div
                                    key={i}
                                    className={cn(
                                        'flex-1 rounded-t-[0.35em]',
                                        anim,
                                        i === WEEK.length - 1
                                            ? 'bg-[var(--w-paper-green)]'
                                            : 'bg-[color-mix(in_oklch,var(--w-paper-green)_35%,var(--w-paper))]',
                                    )}
                                    style={{
                                        height:
                                            t >= 0.3 + i * 0.12
                                                ? `${h * 100}%`
                                                : '0%',
                                    }}
                                />
                            ))}
                        </div>
                        <div className="mt-[0.4em] flex gap-[0.8em]">
                            {WEEK.map((_, i) => (
                                <span
                                    key={i}
                                    className={cn(
                                        'flex-1 text-center text-[0.7em] capitalize',
                                        i === WEEK.length - 1
                                            ? 'font-bold text-[var(--w-paper-green)]'
                                            : 'text-[var(--w-paper-soft)]',
                                    )}
                                >
                                    {i === WEEK.length - 1
                                        ? tr('partner.video.stage.today')
                                        : weekday
                                              .format(new Date(2026, 0, 3 + i))
                                              .replace('.', '')}
                                </span>
                            ))}
                        </div>
                    </div>
                    <div>
                        <p className="text-[0.85em] font-bold">
                            {tr('partner.video.stage.top_sellers')}
                        </p>
                        <ul className="mt-[0.6em] flex flex-col gap-[0.8em]">
                            {TOP_SELLERS.map((row) => (
                                <li
                                    key={row.item}
                                    className={cn(anim, appear(t >= row.at))}
                                >
                                    <span className="flex justify-between text-[0.8em]">
                                        <span>
                                            {tr(
                                                `partner.video.stage.items.${row.item}`,
                                            )}
                                        </span>
                                        <span className="font-receipt tabular-nums">
                                            {row.count}
                                        </span>
                                    </span>
                                    <span className="mt-[0.25em] block h-[0.45em] rounded-full bg-[var(--w-paper-rule)]">
                                        <span
                                            className="block h-full rounded-full bg-[var(--w-paper-green)]"
                                            style={{
                                                width: `${(row.count / 214) * 100}%`,
                                            }}
                                        />
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
                <p className="absolute right-[1.6em] bottom-[0.6em] text-[0.65em] text-[var(--w-paper-soft)]">
                    {tr('partner.video.stage.sample')}
                </p>
            </div>
        </div>
    );
}

function LoyaltyScene({ t }: { t: number }) {
    const { t: tr } = useTranslation();
    const stamps = [0.5, 1.0, 1.5, 2.0, 2.5].filter((at) => t >= at).length;
    const venue = tr('partner.video.stage.venue');

    return (
        <div className="absolute inset-0 flex items-center justify-center gap-[4em]">
            <div className="w-[24em]">
                <div className="rounded-[1em] bg-[var(--w-paper)] p-[1.4em] text-[var(--w-paper-ink)] shadow-[0_1.5em_3em_-1em_oklch(0.1_0.03_155/0.6)]">
                    <p className="text-[0.8em] font-bold tracking-wide text-[var(--w-paper-green)] uppercase">
                        {tr('partner.video.stage.loyalty_card')}
                    </p>
                    <p className="text-[1.3em] font-bold">{venue}</p>
                    <div className="mt-[1em] flex justify-between">
                        {Array.from({ length: 5 }, (_, i) => (
                            <span
                                key={i}
                                className={cn(
                                    'flex size-[3.2em] items-center justify-center rounded-full border-[0.15em]',
                                    anim,
                                    i < stamps
                                        ? 'scale-100 border-[var(--w-paper-green)] bg-[var(--w-paper-green)] text-[var(--w-on-green)]'
                                        : 'scale-90 border-dashed border-[var(--w-paper-rule)] text-transparent',
                                )}
                            >
                                <Star
                                    className="size-[1.5em]"
                                    fill="currentColor"
                                />
                            </span>
                        ))}
                    </div>
                    <p className="mt-[0.8em] text-[0.85em] text-[var(--w-paper-soft)]">
                        {tr('partner.video.stage.visit', {
                            count: stamps,
                            total: 5,
                        })}
                    </p>
                </div>
                <div
                    className={cn(
                        'mt-[1em] flex items-center gap-[0.8em] rounded-[0.8em] border-[0.18em] border-dashed border-[var(--w-amber)] bg-[color-mix(in_oklch,var(--w-amber)_18%,transparent)] p-[1em] text-[var(--w-on-green)]',
                        anim,
                        t >= 3.0
                            ? 'scale-100 opacity-100'
                            : 'scale-90 opacity-0',
                    )}
                >
                    <Ticket className="size-[2em] shrink-0 text-[var(--w-amber)]" />
                    <span>
                        <span className="block text-[0.8em] text-[var(--w-on-green-soft)]">
                            {tr('partner.video.stage.coupon_unlocked')}
                        </span>
                        <span className="block text-[1.1em] font-bold">
                            {tr('partner.video.stage.coupon')}
                        </span>
                    </span>
                </div>
            </div>

            <div
                className={cn(
                    'w-[19em] overflow-hidden rounded-[1em] bg-[var(--w-paper)] text-[var(--w-paper-ink)] shadow-[0_1.5em_3em_-1em_oklch(0.1_0.03_155/0.6)]',
                    anim,
                    appear(t >= 3.5),
                )}
            >
                <div className="flex items-center gap-[0.6em] p-[0.8em]">
                    <span className="flex size-[2.2em] items-center justify-center rounded-full bg-[var(--w-paper-green)] text-[0.85em] font-bold text-[var(--w-on-green)]">
                        {venue
                            .split(' ')
                            .filter((word) => word.length > 2)
                            .map((word) => word[0])
                            .join('')
                            .slice(0, 2)}
                    </span>
                    <span className="text-[0.9em] font-bold">{venue}</span>
                </div>
                <div
                    className="flex h-[10em] items-end p-[1em]"
                    style={{
                        background:
                            'linear-gradient(160deg, oklch(0.55 0.14 40), oklch(0.32 0.08 20))',
                    }}
                >
                    <p className="font-display text-[1.9em] leading-[0.95] font-extrabold text-white uppercase">
                        {tr('partner.video.stage.post')}
                    </p>
                </div>
                <p className="flex items-center gap-[0.4em] p-[0.8em] text-[0.85em] text-[var(--w-paper-soft)]">
                    <Heart
                        className="size-[1.1em] text-[var(--w-stamp)]"
                        fill="currentColor"
                    />
                    48
                </p>
            </div>
        </div>
    );
}

function EndScene({ t }: { t: number }) {
    const { t: tr } = useTranslation();

    return (
        <div className="absolute inset-0 flex flex-col items-center justify-center text-[var(--w-on-green)]">
            <div
                className={cn(
                    'flex items-center gap-[1.2em]',
                    anim,
                    t >= 0.2 ? 'scale-100 opacity-100' : 'scale-90 opacity-0',
                )}
            >
                <AppLogoIcon tone="white" className="h-[7em] w-[5em]" />
                <AppLogoWordmark tone="white" className="h-[3.6em]" />
            </div>
            <p
                className={cn(
                    'mt-[1.4em] font-display text-[2.6em] font-bold uppercase',
                    anim,
                    appear(t >= 0.9),
                )}
            >
                {tr('partner.video.scenes.end.body')}
            </p>
        </div>
    );
}

const SCENE_COMPONENTS: Record<SceneKey, (props: { t: number }) => ReactNode> =
    {
        rush: RushScene,
        qr: QrScene,
        queue: QueueScene,
        call: CallScene,
        pay: PayScene,
        dash: DashScene,
        loyalty: LoyaltyScene,
        end: EndScene,
    };

/* ---------- player ---------- */

function formatClock(seconds: number): string {
    const whole = Math.floor(seconds);

    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
}

export function PitchVideo({ className }: { className?: string }) {
    const { t } = useTranslation();
    const [time, setTime] = useState(0);
    const [playing, setPlaying] = useState(false);
    const [canFullscreen, setCanFullscreen] = useState(false);
    const [fullscreen, setFullscreen] = useState(false);
    const playerRef = useRef<HTMLDivElement>(null);

    const ended = time >= TOTAL;
    const running = playing && !ended;
    const index = sceneIndexAt(time);
    const scene = SCENES[index];

    useEffect(() => {
        if (!running) {
            return;
        }

        let frame = 0;
        let last = performance.now();
        let pending = 0;

        const tick = (now: number) => {
            // Clamped, so a tab coming back from the background doesn't skip ahead.
            pending += Math.min(1, (now - last) / 1000);
            last = now;

            if (pending >= TICK) {
                const step = pending;
                pending = 0;
                setTime((current) => Math.min(TOTAL, current + step));
            }

            frame = requestAnimationFrame(tick);
        };

        frame = requestAnimationFrame(tick);

        return () => cancelAnimationFrame(frame);
    }, [running]);

    useEffect(() => {
        const sync = () => {
            setCanFullscreen(Boolean(document.fullscreenEnabled));
            setFullscreen(document.fullscreenElement === playerRef.current);
        };

        sync();
        document.addEventListener('fullscreenchange', sync);

        return () => document.removeEventListener('fullscreenchange', sync);
    }, []);

    const toggle = () => {
        if (ended) {
            setTime(0);
            setPlaying(true);

            return;
        }

        setPlaying((current) => !current);
    };

    const seek = (sceneIndex: number) => {
        setTime(STARTS[sceneIndex]);
        setPlaying(true);
    };

    const toggleFullscreen = () => {
        if (document.fullscreenElement) {
            void document.exitFullscreen();
        } else {
            void playerRef.current?.requestFullscreen();
        }
    };

    const started = time > 0 || playing;

    return (
        <div
            ref={playerRef}
            className={cn(
                'overflow-hidden rounded-[1.25rem] bg-[oklch(0.2_0.035_157)] shadow-[0_30px_60px_-30px_oklch(0.2_0.05_157/0.7)]',
                fullscreen &&
                    'flex h-full flex-col justify-center rounded-none bg-[oklch(0.16_0.03_157)]',
                className,
            )}
        >
            <div
                className={cn(
                    't4a-stage-wrap w-full',
                    fullscreen && 'mx-auto max-w-[calc((100svh-12rem)*16/9)]',
                )}
            >
                {/* Tapping the picture plays/pauses, like a video; keyboard users have the button below. */}
                <div
                    onClick={toggle}
                    className="relative aspect-video w-full cursor-pointer overflow-hidden"
                    style={{
                        backgroundColor: 'oklch(0.3 0.06 157)',
                        backgroundImage:
                            'linear-gradient(oklch(0.96 0.02 90 / 0.06) 1px, transparent 1px), linear-gradient(90deg, oklch(0.96 0.02 90 / 0.06) 1px, transparent 1px)',
                        backgroundSize: '4% 7.1%',
                    }}
                >
                    <div
                        className="t4a-stage absolute inset-0"
                        aria-hidden="true"
                    >
                        {SCENES.map((s, i) => {
                            const Scene = SCENE_COMPONENTS[s.key];
                            const local =
                                i < index
                                    ? s.duration
                                    : i > index
                                      ? 0
                                      : time - STARTS[i];

                            return (
                                <div
                                    key={s.key}
                                    className={cn(
                                        'absolute inset-0 transition-opacity duration-500 motion-reduce:transition-none',
                                        i === index
                                            ? 'opacity-100'
                                            : 'opacity-0',
                                    )}
                                >
                                    <Scene t={local} />
                                </div>
                            );
                        })}

                        {!started && (
                            <div className="absolute inset-0 flex flex-col items-center justify-center gap-[1.2em] bg-[oklch(0.15_0.03_157/0.55)] text-[var(--w-on-green)]">
                                <span className="flex size-[6em] items-center justify-center rounded-full bg-[var(--w-on-green)] text-[oklch(0.3_0.07_157)] shadow-[0_1em_2em_-0.5em_oklch(0.1_0.03_155/0.7)]">
                                    <Play
                                        className="ml-[0.3em] size-[2.8em]"
                                        fill="currentColor"
                                    />
                                </span>
                                <span className="text-[1.6em] font-semibold">
                                    {t('partner.video.poster', {
                                        seconds: Math.round(TOTAL),
                                    })}
                                </span>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <div
                className={cn(
                    'w-full px-4 pt-4 pb-3 text-[oklch(0.96_0.02_90)] sm:px-6 sm:pt-5',
                    fullscreen && 'mx-auto max-w-[calc((100svh-12rem)*16/9)]',
                )}
            >
                <div
                    aria-live="polite"
                    className="min-h-[5.5rem] sm:min-h-[4.5rem]"
                >
                    <p className="font-display text-[1.6rem] leading-tight font-bold sm:text-[1.9rem]">
                        {t(`partner.video.scenes.${scene.key}.title`)}
                    </p>
                    {scene.key !== 'end' && (
                        <p className="mt-1 text-[15px] leading-snug text-[oklch(0.85_0.045_140)] sm:text-base">
                            {t(`partner.video.scenes.${scene.key}.body`)}
                        </p>
                    )}
                </div>

                <div className="mt-4 flex items-center gap-3">
                    <button
                        type="button"
                        onClick={toggle}
                        aria-label={
                            ended
                                ? t('partner.video.controls.replay')
                                : running
                                  ? t('partner.video.controls.pause')
                                  : t('partner.video.controls.play')
                        }
                        className="flex size-10 shrink-0 items-center justify-center rounded-full bg-[oklch(0.96_0.02_90)] text-[oklch(0.3_0.07_157)] transition-transform hover:scale-105 focus-visible:outline-[oklch(0.96_0.02_90)]"
                    >
                        {ended ? (
                            <RotateCcw className="size-5" />
                        ) : running ? (
                            <Pause className="size-5" fill="currentColor" />
                        ) : (
                            <Play
                                className="ml-0.5 size-5"
                                fill="currentColor"
                            />
                        )}
                    </button>

                    <div
                        className="flex flex-1 gap-1"
                        role="group"
                        aria-label={t('partner.video.controls.chapters')}
                    >
                        {SCENES.map((s, i) => {
                            const fill =
                                i < index
                                    ? 1
                                    : i > index
                                      ? 0
                                      : (time - STARTS[i]) / s.duration;

                            return (
                                <button
                                    key={s.key}
                                    type="button"
                                    onClick={() => seek(i)}
                                    aria-label={t(
                                        `partner.video.scenes.${s.key}.title`,
                                    )}
                                    aria-current={
                                        i === index ? 'step' : undefined
                                    }
                                    className="group flex h-6 items-center focus-visible:outline-[oklch(0.96_0.02_90)]"
                                    style={{
                                        flexGrow: s.duration,
                                        flexBasis: 0,
                                    }}
                                >
                                    <span className="block h-1.5 w-full overflow-hidden rounded-full bg-[oklch(0.96_0.02_90/0.22)] transition-[height] group-hover:h-2.5">
                                        <span
                                            className="block h-full bg-[oklch(0.85_0.15_157)] transition-[width] duration-100 ease-linear"
                                            style={{
                                                width: `${Math.min(1, fill) * 100}%`,
                                            }}
                                        />
                                    </span>
                                </button>
                            );
                        })}
                    </div>

                    <span className="hidden shrink-0 font-receipt text-sm text-[oklch(0.85_0.045_140)] tabular-nums sm:inline">
                        {formatClock(time)} / {formatClock(TOTAL)}
                    </span>

                    {canFullscreen && (
                        <button
                            type="button"
                            onClick={toggleFullscreen}
                            aria-label={
                                fullscreen
                                    ? t(
                                          'partner.video.controls.exit_fullscreen',
                                      )
                                    : t('partner.video.controls.fullscreen')
                            }
                            className="flex size-10 shrink-0 items-center justify-center rounded-full text-[oklch(0.96_0.02_90)] hover:bg-[oklch(0.96_0.02_90/0.12)] focus-visible:outline-[oklch(0.96_0.02_90)]"
                        >
                            {fullscreen ? (
                                <Minimize className="size-5" />
                            ) : (
                                <Maximize className="size-5" />
                            )}
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}
