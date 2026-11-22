import { Head, router, usePage } from '@inertiajs/react';
import {
    Check,
    ChefHat,
    CircleCheck,
    ConciergeBell,
    Crown,
    ImageOff,
    LogOut,
    Minus,
    Plus,
    Search,
    ShoppingBag,
    UserPlus,
    UtensilsCrossed,
    Wallet,
    X,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import JoinController from '@/actions/App/Http/Controllers/Customer/JoinController';
import { ItemNote } from '@/components/item-note';
import { SoldOutBadge } from '@/components/sold-out-badge';
import { TableCallButtons } from '@/components/table-call-buttons';
import type { TableCalls } from '@/components/table-call-buttons';
import { TableChat } from '@/components/table-chat';
import type { ChatFeed } from '@/components/table-chat';
import { PayPanel, PaymentsList } from '@/components/table-payment';
import type {
    CouponQuote,
    OpenCheckout,
    TablePaymentEntry,
} from '@/components/table-payment';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useLiveReload } from '@/hooks/use-live-reload';
import { formatBRL } from '@/lib/money';
import { newRequestKey } from '@/lib/request-key';
import { serviceFeeRate } from '@/lib/service-fee';
import type { ServiceFeeTerms } from '@/lib/service-fee';
import { cn } from '@/lib/utils';

type MenuItem = {
    id: number;
    category_id: number | null;
    category_name: string | null;
    name: string;
    description: string | null;
    price: number;
    price_type: string;
    has_queue: boolean;
    /** Esgotado: shown, but can't be ordered. */
    sold_out: boolean;
    picture_url: string | null;
};

type OrderLine = {
    id: number;
    name: string;
    description: string | null;
    price: number;
    price_type: string;
    quantity: number;
    picture_url: string | null;
    ordered_by: string | null;
};

type PreparingItem = {
    name: string;
    price: number;
    quantity: number;
    ordered_by: string | null;
    /** For the kitchen or the waiter ("sem cebola"). */
    notes: string | null;
    has_queue: boolean;
};

/** Longest note on an item (QueueItem::NOTES_MAX). */
const NOTES_MAX = 140;

type Member = {
    id: number;
    name: string;
    is_responsible: boolean;
    is_me: boolean;
};

type JoinRequest = {
    id: number;
    name: string;
};

type Props = {
    code: string;
    tableTitle: string;
    restaurant: string | null;
    menu: MenuItem[];
    products: OrderLine[];
    preparing: PreparingItem[];
    /** The products delivered. */
    subtotal: number;
    /** The service fee on top of them (0 when none or waived). */
    serviceFee: number;
    /** How the fee is worked out, for its label; null when there is none. */
    serviceFeeTerms: ServiceFeeTerms | null;
    /** Products plus service fee: what the table owes. */
    total: number;
    paid: number;
    remaining: number;
    isResponsible: boolean;
    members: Member[];
    joinRequests: JoinRequest[];
    chat: ChatFeed;
    chatReactions: string[];
    chatMaxLength: number;
    onlinePayments: { enabled: boolean; provider: string | null; min: number };
    payments: TablePaymentEntry[];
    myCheckout: OpenCheckout | null;
    couponQuote?: CouponQuote;
    /** Open calls to staff (waiter, bill). */
    calls: TableCalls;
};

type Tab = 'orders' | 'bill' | 'table';

/** The `aba` query parameter (Portuguese, like the URLs) opens a tab directly. */
const TAB_PARAMS: Record<string, Tab> = {
    pedidos: 'orders',
    conta: 'bill',
    mesa: 'table',
};

/**
 * The customer's screen at the table. Mobile first: who and where at the top
 * with what's left to pay, then one tab at a time (orders, bill, people and
 * chat) instead of everything stacked, and the two main actions (order, pay)
 * always within reach at the bottom.
 */
export default function CustomerShow({
    code,
    tableTitle,
    restaurant,
    menu,
    products,
    preparing,
    subtotal,
    serviceFee,
    serviceFeeTerms,
    total,
    paid,
    remaining,
    isResponsible,
    members,
    joinRequests,
    chat,
    chatReactions,
    chatMaxLength,
    onlinePayments,
    payments,
    myCheckout,
    couponQuote,
    calls,
}: Props) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language === 'pt_BR' ? 'pt-BR' : 'en-US';
    const fmt = (n: number) => formatBRL(n, locale);
    const { url } = usePage();

    const [tab, setTab] = useState<Tab>(() => {
        const param = new URLSearchParams(url.split('?')[1] ?? '').get('aba');

        return (param && TAB_PARAMS[param]) || 'orders';
    });
    const [menuOpen, setMenuOpen] = useState(false);
    const tabsRef = useRef<HTMLDivElement>(null);

    // A new tab starts at its top, not wherever the previous one was scrolled to.
    function openTab(next: Tab) {
        setTab(next);

        // A marker just above the (sticky) tabs tells where they start in the page.
        const top = tabsRef.current?.getBoundingClientRect().top;

        if (top !== undefined && top < 0) {
            window.scrollTo({ top: window.scrollY + top });
        }
    }

    // Chat messages from others that arrived while the customer was on another tab.
    const othersMessages = chat.messages.filter((m) => !m.mine).length;
    const [seenMessages, setSeenMessages] = useState(othersMessages);

    if (tab === 'table' && seenMessages !== othersMessages) {
        setSeenMessages(othersMessages);
    }

    const unreadMessages = Math.max(0, othersMessages - seenMessages);

    // Show kitchen progress, deliveries and payments without the customer reloading.
    // Paused while the menu is open so the list doesn't shift under their finger.
    useLiveReload({
        only: [
            'products',
            'preparing',
            'subtotal',
            'serviceFee',
            'serviceFeeTerms',
            'total',
            'paid',
            'remaining',
            'members',
            'joinRequests',
            'payments',
            'myCheckout',
            'onlinePayments',
            'calls',
        ],
        intervalMs: 20_000,
        enabled: !menuOpen,
    });

    const kitchen = preparing.filter((item) => item.has_queue);
    const onTheWay = preparing.filter((item) => !item.has_queue);
    const canPay = onlinePayments.enabled && remaining > 0;

    const tabs: { id: Tab; label: string; badge: number }[] = [
        {
            id: 'orders',
            label: t('join_table.tab_orders'),
            badge: preparing.length,
        },
        { id: 'bill', label: t('join_table.tab_bill'), badge: 0 },
        {
            id: 'table',
            label: t('join_table.tab_table'),
            badge: unreadMessages,
        },
    ];

    return (
        <>
            <Head title={tableTitle} />

            <div className="mx-auto flex min-h-full w-full max-w-2xl flex-col px-4 pt-4">
                <TableHeader
                    restaurant={restaurant}
                    tableTitle={tableTitle}
                    isResponsible={isResponsible}
                    total={total}
                    paid={paid}
                    remaining={remaining}
                    fmt={fmt}
                />

                <div className="mt-3">
                    <TableCallButtons code={code} calls={calls} />
                </div>

                {/* Someone is waiting at the door: needs an answer whatever tab is open. */}
                {joinRequests.length > 0 && (
                    <div className="mt-3">
                        <JoinRequestsPanel
                            code={code}
                            requests={joinRequests}
                        />
                    </div>
                )}

                <div ref={tabsRef} aria-hidden="true" />
                <div
                    role="tablist"
                    className="sticky top-0 z-10 -mx-4 mt-3 bg-background/95 px-4 py-2 backdrop-blur"
                >
                    <div className="grid grid-cols-3 gap-1 rounded-xl bg-muted p-1">
                        {tabs.map((item) => (
                            <button
                                key={item.id}
                                type="button"
                                role="tab"
                                aria-selected={tab === item.id}
                                onClick={() => openTab(item.id)}
                                className={cn(
                                    'flex h-9 items-center justify-center gap-1.5 rounded-lg text-sm font-medium transition-colors',
                                    tab === item.id
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {item.label}
                                {item.badge > 0 && (
                                    <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[0.7rem] font-bold text-primary-foreground tabular-nums">
                                        {item.badge}
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="flex-1 space-y-4 pt-2 pb-6">
                    {tab === 'orders' && (
                        <OrdersTab
                            kitchen={kitchen}
                            onTheWay={onTheWay}
                            delivered={products}
                            bill={
                                <BillBreakdown
                                    subtotal={subtotal}
                                    serviceFee={serviceFee}
                                    terms={serviceFeeTerms}
                                    total={total}
                                    fmt={fmt}
                                />
                            }
                            fmt={fmt}
                            onOpenMenu={() => setMenuOpen(true)}
                        />
                    )}

                    {tab === 'bill' && (
                        <>
                            {total > 0 && (
                                <div className="overflow-hidden rounded-xl border border-border bg-card">
                                    <BillBreakdown
                                        subtotal={subtotal}
                                        serviceFee={serviceFee}
                                        terms={serviceFeeTerms}
                                        total={total}
                                        fmt={fmt}
                                    />
                                </div>
                            )}
                            <PayPanel
                                code={code}
                                remaining={remaining}
                                enabled={onlinePayments.enabled}
                                provider={onlinePayments.provider}
                                minimum={onlinePayments.min}
                                myCheckout={myCheckout}
                                couponQuote={couponQuote}
                            />
                            <PaymentsList payments={payments} />
                            {payments.length === 0 && remaining <= 0 && (
                                <p className="py-8 text-center text-sm text-muted-foreground">
                                    {t('join_table.nothing_on_bill')}
                                </p>
                            )}
                        </>
                    )}

                    {tab === 'table' && (
                        <MembersPanel
                            code={code}
                            members={members}
                            isResponsible={isResponsible}
                        />
                    )}

                    {/* Always mounted: it keeps polling and floats reactions up from any tab. */}
                    <TableChat
                        code={code}
                        chat={chat}
                        reactions={chatReactions}
                        maxLength={chatMaxLength}
                        hidden={tab !== 'table'}
                    />

                    {tab === 'table' && (
                        <LeaveTable
                            code={code}
                            members={members}
                            isResponsible={isResponsible}
                        />
                    )}
                </div>

                {/* The two things people come to this screen for, always within reach. */}
                <div className="sticky bottom-0 z-10 -mx-4 border-t border-border bg-background/95 px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur">
                    <div className="flex gap-2">
                        {canPay && tab !== 'bill' && (
                            <Button
                                variant="outline"
                                size="lg"
                                className="flex-1"
                                onClick={() => openTab('bill')}
                            >
                                <Wallet className="h-4 w-4" />
                                {t('join_table.pay_cta')}
                            </Button>
                        )}
                        <Button
                            size="lg"
                            className="flex-1"
                            onClick={() => setMenuOpen(true)}
                        >
                            <ShoppingBag className="h-4 w-4" />
                            {t('join_table.order_cta')}
                        </Button>
                    </div>
                </div>
            </div>

            <MenuDialog
                code={code}
                menu={menu}
                open={menuOpen}
                onOpenChange={setMenuOpen}
                fmt={fmt}
            />
        </>
    );
}

CustomerShow.layout = {
    breadcrumbs: [{ title: 'nav.table', href: JoinController.index.url() }],
};

/** Where the customer is, and how much of the bill is left. */
function TableHeader({
    restaurant,
    tableTitle,
    isResponsible,
    total,
    paid,
    remaining,
    fmt,
}: {
    restaurant: string | null;
    tableTitle: string;
    isResponsible: boolean;
    total: number;
    paid: number;
    remaining: number;
    fmt: (n: number) => string;
}) {
    const { t } = useTranslation();
    const progress = total > 0 ? Math.min(100, (paid / total) * 100) : 0;
    const settled = total > 0 && remaining <= 0;

    return (
        <div className="space-y-3 rounded-2xl border border-border bg-card p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    {restaurant && (
                        <p className="truncate text-xs font-medium text-muted-foreground">
                            {restaurant}
                        </p>
                    )}
                    <h1 className="truncate text-xl font-bold">{tableTitle}</h1>
                </div>
                {isResponsible && (
                    <span
                        className="flex shrink-0 items-center gap-1 rounded-full bg-amber-500/10 px-2 py-1 text-xs font-medium text-amber-600 dark:text-amber-400"
                        title={t('join_table.you_are_responsible')}
                    >
                        <Crown className="h-3.5 w-3.5" />
                        {t('join_table.responsible')}
                    </span>
                )}
            </div>

            {total <= 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('join_table.nothing_on_bill')}
                </p>
            ) : (
                <div className="space-y-2">
                    <div className="flex items-end justify-between gap-3">
                        <div>
                            <p className="text-xs text-muted-foreground">
                                {settled
                                    ? t('join_table.bill_paid')
                                    : t('join_table.left_to_pay')}
                            </p>
                            <p
                                className={cn(
                                    'text-2xl font-bold tabular-nums',
                                    settled && 'text-green-600',
                                )}
                            >
                                {settled ? (
                                    <span className="flex items-center gap-1.5">
                                        <CircleCheck className="h-6 w-6" />
                                        {fmt(total)}
                                    </span>
                                ) : (
                                    fmt(remaining)
                                )}
                            </p>
                        </div>
                        {!settled && (
                            <p className="text-right text-xs text-muted-foreground tabular-nums">
                                {t('join_table.paid_of', {
                                    paid: fmt(paid),
                                    total: fmt(total),
                                })}
                            </p>
                        )}
                    </div>
                    <div
                        className="h-1.5 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={Math.round(progress)}
                    >
                        <div
                            className="h-full rounded-full bg-green-600 transition-[width] duration-500"
                            style={{ width: `${progress}%` }}
                        />
                    </div>
                </div>
            )}
        </div>
    );
}

/**
 * The bill: the products, the service fee on its own line (with how it is
 * worked out) and the total. Without a fee, just the total.
 */
function BillBreakdown({
    subtotal,
    serviceFee,
    terms,
    total,
    fmt,
}: {
    subtotal: number;
    serviceFee: number;
    terms: ServiceFeeTerms | null;
    total: number;
    fmt: (n: number) => string;
}) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language === 'pt_BR' ? 'pt-BR' : 'en-US';

    return (
        <div className="space-y-1.5 px-4 py-3">
            {terms && (
                <>
                    <div className="flex items-baseline justify-between text-sm text-muted-foreground">
                        <span>{t('join_table.subtotal')}</span>
                        <span className="tabular-nums">{fmt(subtotal)}</span>
                    </div>
                    <div className="flex items-baseline justify-between gap-3 text-sm text-muted-foreground">
                        <span>
                            {t('join_table.service_fee', {
                                rate: serviceFeeRate(terms, locale),
                            })}
                            {terms.type === 'percent' && terms.cap !== null && (
                                <span className="block text-xs">
                                    {t('join_table.service_fee_cap', {
                                        cap: fmt(terms.cap),
                                    })}
                                </span>
                            )}
                        </span>
                        <span className="shrink-0 tabular-nums">
                            {fmt(serviceFee)}
                        </span>
                    </div>
                </>
            )}
            <div className="flex items-baseline justify-between">
                <span className="text-sm font-semibold">
                    {t('join_table.table_total')}
                </span>
                <span className="font-bold tabular-nums">{fmt(total)}</span>
            </div>
        </div>
    );
}

type ListLine = {
    name: string;
    quantity: number;
    price: number;
    ordered_by: string | null;
    picture_url?: string | null;
    notes?: string | null;
};

/** Everything the table ordered, by where it is: kitchen, on the way, on the table. */
function OrdersTab({
    kitchen,
    onTheWay,
    delivered,
    bill,
    fmt,
    onOpenMenu,
}: {
    kitchen: PreparingItem[];
    onTheWay: PreparingItem[];
    delivered: OrderLine[];
    /** The bill under the delivered items: products, service fee and total. */
    bill: React.ReactNode;
    fmt: (n: number) => string;
    onOpenMenu: () => void;
}) {
    const { t } = useTranslation();

    if (
        kitchen.length === 0 &&
        onTheWay.length === 0 &&
        delivered.length === 0
    ) {
        return (
            <div className="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-border px-6 py-12 text-center">
                <UtensilsCrossed className="h-8 w-8 text-muted-foreground/60" />
                <div className="space-y-1">
                    <p className="font-semibold">
                        {t('join_table.no_orders_title')}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {t('join_table.no_orders_description')}
                    </p>
                </div>
                <Button onClick={onOpenMenu}>
                    <ShoppingBag className="h-4 w-4" />
                    {t('join_table.see_menu')}
                </Button>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {kitchen.length > 0 && (
                <OrderSection
                    icon={<ChefHat className="h-4 w-4" />}
                    title={t('join_table.preparing_title')}
                    tone="text-amber-600 dark:text-amber-400"
                    pulse
                    lines={kitchen}
                    fmt={fmt}
                />
            )}
            {onTheWay.length > 0 && (
                <OrderSection
                    icon={<ConciergeBell className="h-4 w-4" />}
                    title={t('join_table.pending_delivery_title')}
                    tone="text-blue-600 dark:text-blue-400"
                    lines={onTheWay}
                    fmt={fmt}
                />
            )}
            {delivered.length > 0 && (
                <OrderSection
                    icon={<UtensilsCrossed className="h-4 w-4" />}
                    title={t('join_table.delivered_title')}
                    tone="text-muted-foreground"
                    lines={delivered}
                    fmt={fmt}
                    footer={
                        <div className="border-t border-border">{bill}</div>
                    }
                />
            )}
        </div>
    );
}

function OrderSection({
    icon,
    title,
    tone,
    pulse = false,
    lines,
    fmt,
    footer,
}: {
    icon: React.ReactNode;
    title: string;
    tone: string;
    pulse?: boolean;
    lines: ListLine[];
    fmt: (n: number) => string;
    footer?: React.ReactNode;
}) {
    return (
        <section className="space-y-2">
            <h2
                className={cn(
                    'flex items-center gap-1.5 text-xs font-semibold tracking-wide uppercase',
                    tone,
                )}
            >
                {icon}
                {title}
                {pulse && (
                    <span className="relative ml-0.5 flex h-2 w-2">
                        <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75" />
                        <span className="relative inline-flex h-2 w-2 rounded-full bg-amber-500" />
                    </span>
                )}
            </h2>
            <div className="overflow-hidden rounded-xl border border-border bg-card">
                <ul className="divide-y divide-border">
                    {lines.map((line, i) => (
                        <li
                            key={i}
                            className="flex items-center gap-3 px-4 py-2.5"
                        >
                            {line.picture_url && (
                                <img
                                    src={line.picture_url}
                                    alt=""
                                    loading="lazy"
                                    decoding="async"
                                    className="h-10 w-10 shrink-0 rounded-lg object-cover"
                                />
                            )}
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm">
                                    <span className="font-semibold tabular-nums">
                                        {line.quantity}×
                                    </span>{' '}
                                    {line.name}
                                </p>
                                {line.ordered_by && (
                                    <p className="truncate text-xs text-muted-foreground">
                                        {line.ordered_by}
                                    </p>
                                )}
                                <ItemNote
                                    notes={line.notes}
                                    className="mt-1 w-fit text-xs"
                                />
                            </div>
                            <span className="text-sm font-medium tabular-nums">
                                {fmt(line.price * line.quantity)}
                            </span>
                        </li>
                    ))}
                </ul>
                {footer}
            </div>
        </section>
    );
}

/** The menu, then a review of the cart, then the order goes to the kitchen. */
function MenuDialog({
    code,
    menu,
    open,
    onOpenChange,
    fmt,
}: {
    code: string;
    menu: MenuItem[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    fmt: (n: number) => string;
}) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');
    const [activeCategory, setActiveCategory] = useState<number | null>(null);
    const [cart, setCart] = useState<Record<number, number>>({});
    // A note per product in the cart, for the kitchen ("sem cebola").
    const [notes, setNotes] = useState<Record<number, string>>({});
    const [step, setStep] = useState<'menu' | 'review'>('menu');
    const [submitting, setSubmitting] = useState(false);
    // Identifies this cart to the server: re-sending it after a timeout or a double
    // tap is ignored instead of ordering twice. A changed cart is a new order.
    const [requestKey, setRequestKey] = useState(newRequestKey);

    const categories = useMemo(() => {
        const seen = new Set<number>();

        return menu
            .filter(
                (item) =>
                    item.category_id !== null && item.category_name !== null,
            )
            .filter((item) => {
                if (seen.has(item.category_id!)) {
                    return false;
                }

                seen.add(item.category_id!);

                return true;
            })
            .map((item) => ({
                id: item.category_id!,
                name: item.category_name!,
            }));
    }, [menu]);

    const filteredMenu = useMemo(() => {
        const q = search.trim().toLowerCase();

        return menu.filter((item) => {
            const matchesSearch =
                q === '' ||
                item.name.toLowerCase().includes(q) ||
                (item.description?.toLowerCase().includes(q) ?? false);
            const matchesCategory =
                activeCategory === null || item.category_id === activeCategory;

            return matchesSearch && matchesCategory;
        });
    }, [menu, search, activeCategory]);

    const totalCartItems = useMemo(
        () => Object.values(cart).reduce((sum, qty) => sum + qty, 0),
        [cart],
    );

    const cartLines = useMemo(
        () =>
            Object.entries(cart)
                .map(([id, qty]) => {
                    const item = menu.find((m) => m.id === Number(id));

                    return item ? { item, quantity: qty } : null;
                })
                .filter(Boolean) as { item: MenuItem; quantity: number }[],
        [cart, menu],
    );

    const cartTotal = useMemo(
        () =>
            cartLines.reduce(
                (sum, { item, quantity }) => sum + item.price * quantity,
                0,
            ),
        [cartLines],
    );

    function increment(id: number) {
        setRequestKey(newRequestKey());
        setCart((prev) => ({ ...prev, [id]: (prev[id] ?? 0) + 1 }));
    }

    function decrement(id: number) {
        setRequestKey(newRequestKey());
        setCart((prev) => {
            if ((prev[id] ?? 0) <= 1) {
                const next = { ...prev };
                delete next[id];

                return next;
            }

            return { ...prev, [id]: prev[id] - 1 };
        });
    }

    function changeNote(id: number, value: string) {
        setRequestKey(newRequestKey());
        setNotes((prev) => ({ ...prev, [id]: value.slice(0, NOTES_MAX) }));
    }

    function close() {
        onOpenChange(false);
        setCart({});
        setNotes({});
        setStep('menu');
        setSearch('');
        setActiveCategory(null);
    }

    function submitOrder() {
        if (totalCartItems === 0 || submitting) {
            return;
        }

        setSubmitting(true);
        router.post(
            JoinController.order.url(code),
            {
                items: Object.entries(cart).map(([productId, quantity]) => ({
                    product_id: Number(productId),
                    quantity,
                    notes: notes[Number(productId)]?.trim() || null,
                })),
                request_key: requestKey,
            },
            {
                preserveScroll: true,
                onSuccess: () => close(),
                // Something sold out meanwhile: say what and show it on the menu,
                // keeping the cart so the customer only takes it out.
                onError: (errors) => {
                    if (errors.items) {
                        toast.error(errors.items);
                        router.reload({ only: ['menu'] });
                    }
                },
                onFinish: () => setSubmitting(false),
            },
        );
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => (next ? onOpenChange(true) : close())}
        >
            <DialogContent className="flex max-h-[85vh] flex-col gap-0 p-0 sm:max-w-lg">
                <DialogHeader className="shrink-0 border-b border-border px-6 py-4">
                    <DialogTitle>
                        {step === 'menu'
                            ? t('join_table.menu_title')
                            : t('join_table.review_title')}
                    </DialogTitle>
                </DialogHeader>

                {step === 'menu' ? (
                    <>
                        <div className="shrink-0 space-y-3 border-b border-border px-4 py-3">
                            <div className="relative">
                                <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    placeholder={t(
                                        'join_table.search_placeholder',
                                    )}
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    className="pl-9"
                                />
                            </div>

                            {categories.length > 0 && (
                                <div className="flex scrollbar-none gap-1.5 overflow-x-auto pb-0.5">
                                    <CategoryChip
                                        active={activeCategory === null}
                                        onClick={() => setActiveCategory(null)}
                                    >
                                        {t('join_table.all_categories')}
                                    </CategoryChip>
                                    {categories.map((cat) => (
                                        <CategoryChip
                                            key={cat.id}
                                            active={activeCategory === cat.id}
                                            onClick={() =>
                                                setActiveCategory(cat.id)
                                            }
                                        >
                                            {cat.name}
                                        </CategoryChip>
                                    ))}
                                </div>
                            )}
                        </div>

                        <div className="flex-1 overflow-y-auto">
                            {filteredMenu.length === 0 ? (
                                <p className="py-12 text-center text-sm text-muted-foreground">
                                    {t('join_table.no_products')}
                                </p>
                            ) : (
                                <div className="divide-y divide-border">
                                    {filteredMenu.map((item) => (
                                        <MenuRow
                                            key={item.id}
                                            item={item}
                                            fmt={fmt}
                                            quantity={cart[item.id] ?? 0}
                                            onIncrement={() =>
                                                increment(item.id)
                                            }
                                            onDecrement={() =>
                                                decrement(item.id)
                                            }
                                        />
                                    ))}
                                </div>
                            )}
                        </div>

                        {totalCartItems > 0 && (
                            <div className="shrink-0 border-t border-border p-4">
                                <Button
                                    className="w-full gap-2"
                                    onClick={() => setStep('review')}
                                >
                                    {t('join_table.next')}
                                    <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary-foreground/20 px-1.5 text-xs font-bold text-primary-foreground tabular-nums">
                                        {totalCartItems}
                                    </span>
                                </Button>
                            </div>
                        )}
                    </>
                ) : (
                    <>
                        <div className="flex-1 overflow-y-auto">
                            <div className="divide-y divide-border">
                                {cartLines.map(({ item, quantity }) => (
                                    <ReviewRow
                                        key={item.id}
                                        item={item}
                                        fmt={fmt}
                                        quantity={quantity}
                                        onIncrement={() => increment(item.id)}
                                        onDecrement={() => decrement(item.id)}
                                        note={notes[item.id] ?? ''}
                                        onNoteChange={(value) =>
                                            changeNote(item.id, value)
                                        }
                                    />
                                ))}
                            </div>

                            <div className="flex items-baseline justify-between border-t border-border px-4 py-3">
                                <span className="text-sm font-semibold">
                                    {t('join_table.total')}
                                </span>
                                <span className="text-lg font-bold tabular-nums">
                                    {fmt(cartTotal)}
                                </span>
                            </div>
                        </div>

                        <div className="grid shrink-0 grid-cols-2 gap-3 border-t border-border p-4">
                            <Button
                                variant="outline"
                                onClick={() => setStep('menu')}
                                disabled={submitting}
                            >
                                {t('join_table.back')}
                            </Button>
                            <Button
                                className="gap-2"
                                onClick={submitOrder}
                                disabled={submitting}
                            >
                                <ShoppingBag className="h-4 w-4" />
                                {t('join_table.confirm_order')}
                            </Button>
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function CategoryChip({
    active,
    onClick,
    children,
}: {
    active: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'shrink-0 rounded-full px-3 py-1 text-xs font-medium transition-colors',
                active
                    ? 'bg-primary text-primary-foreground'
                    : 'bg-muted text-muted-foreground hover:bg-muted/80',
            )}
        >
            {children}
        </button>
    );
}

function ProductThumb({ item, size }: { item: MenuItem; size: string }) {
    return (
        <div
            className={cn('shrink-0 overflow-hidden rounded-lg bg-muted', size)}
        >
            {item.picture_url ? (
                <img
                    loading="lazy"
                    decoding="async"
                    src={item.picture_url}
                    alt={item.name}
                    className="h-full w-full object-cover"
                />
            ) : (
                <div className="flex h-full w-full items-center justify-center text-muted-foreground">
                    <ImageOff className="h-4 w-4 opacity-30" />
                </div>
            )}
        </div>
    );
}

function Stepper({
    quantity,
    onIncrement,
    onDecrement,
    canIncrement = true,
}: {
    quantity: number;
    onIncrement: () => void;
    onDecrement: () => void;
    /** False for a product that sold out: it can only come out of the cart. */
    canIncrement?: boolean;
}) {
    return (
        <div className="flex shrink-0 items-center gap-1.5">
            <button
                type="button"
                onClick={onDecrement}
                className="flex h-8 w-8 items-center justify-center rounded-full border border-border bg-muted text-muted-foreground transition-colors hover:bg-muted/80 active:scale-95"
            >
                <Minus className="h-3.5 w-3.5" />
            </button>
            <span className="w-5 text-center text-sm font-bold tabular-nums">
                {quantity}
            </span>
            <button
                type="button"
                onClick={onIncrement}
                disabled={!canIncrement}
                className="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-primary-foreground transition-opacity hover:opacity-90 active:scale-95 disabled:opacity-30"
            >
                <Plus className="h-3.5 w-3.5" />
            </button>
        </div>
    );
}

const PRICE_SUFFIX: Record<string, string> = {
    unit: 'un.',
    kg: 'kg',
    '100g': '100g',
    liter: 'L',
    portion: 'porção',
};

function ReviewRow({
    item,
    fmt,
    quantity,
    onIncrement,
    onDecrement,
    note,
    onNoteChange,
}: {
    item: MenuItem;
    fmt: (n: number) => string;
    quantity: number;
    onIncrement: () => void;
    onDecrement: () => void;
    note: string;
    onNoteChange: (value: string) => void;
}) {
    const { t } = useTranslation();

    return (
        <div className="space-y-2 px-4 py-3">
            <div className="flex items-center gap-3">
                <ProductThumb item={item} size="h-12 w-12" />
                <div className="min-w-0 flex-1">
                    <p className="flex items-center gap-2 text-sm font-medium">
                        <span className="truncate">{item.name}</span>
                        {item.sold_out && <SoldOutBadge />}
                    </p>
                    <p className="text-xs font-semibold text-primary tabular-nums">
                        {fmt(item.price * quantity)}
                    </p>
                </div>
                <Stepper
                    quantity={quantity}
                    onIncrement={onIncrement}
                    onDecrement={onDecrement}
                    canIncrement={!item.sold_out}
                />
            </div>
            <Input
                value={note}
                onChange={(e) => onNoteChange(e.target.value)}
                maxLength={NOTES_MAX}
                placeholder={t('join_table.item_notes_placeholder')}
                aria-label={t('join_table.item_notes_label', {
                    product: item.name,
                })}
                className="h-9 text-sm"
            />
        </div>
    );
}

function MenuRow({
    item,
    fmt,
    quantity,
    onIncrement,
    onDecrement,
}: {
    item: MenuItem;
    fmt: (n: number) => string;
    quantity: number;
    onIncrement: () => void;
    onDecrement: () => void;
}) {
    return (
        <div
            className={cn(
                'flex items-center gap-3 px-4 py-3',
                item.sold_out && quantity === 0 && 'opacity-60',
            )}
        >
            <ProductThumb item={item} size="h-12 w-12" />
            <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2 text-sm font-medium">
                    <span className="truncate">{item.name}</span>
                    {item.sold_out && <SoldOutBadge />}
                </p>
                {item.description && (
                    <p className="truncate text-xs text-muted-foreground">
                        {item.description}
                    </p>
                )}
                <p className="text-xs font-semibold text-primary">
                    {fmt(item.price)}
                    <span className="ml-1 font-normal text-muted-foreground">
                        / {PRICE_SUFFIX[item.price_type] ?? item.price_type}
                    </span>
                </p>
            </div>

            {quantity === 0 ? (
                item.sold_out ? null : (
                    <button
                        type="button"
                        onClick={onIncrement}
                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground transition-opacity hover:opacity-90 active:scale-95"
                    >
                        <Plus className="h-4 w-4" />
                    </button>
                )
            ) : (
                <Stepper
                    quantity={quantity}
                    onIncrement={onIncrement}
                    onDecrement={onDecrement}
                    canIncrement={!item.sold_out}
                />
            )}
        </div>
    );
}

function JoinRequestsPanel({
    code,
    requests,
}: {
    code: string;
    requests: JoinRequest[];
}) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState<number | null>(null);

    function decide(customer: number, approve: boolean) {
        if (busy !== null) {
            return;
        }

        const action = approve ? JoinController.approve : JoinController.reject;

        setBusy(customer);
        router.post(
            action.url({ code, customer }),
            {},
            {
                preserveScroll: true,
                onFinish: () => setBusy(null),
            },
        );
    }

    return (
        <div className="space-y-1 rounded-xl border border-primary/40 bg-primary/5 px-4 py-3">
            <p className="flex items-center gap-2 text-sm font-semibold">
                <UserPlus className="h-4 w-4 text-primary" />
                {t('join_table.join_requests_title')}
            </p>
            <ul className="divide-y divide-border">
                {requests.map((request) => (
                    <li
                        key={request.id}
                        className="flex items-center gap-2 py-2"
                    >
                        <span className="min-w-0 flex-1 truncate text-sm">
                            {request.name}
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={busy !== null}
                            onClick={() => decide(request.id, false)}
                            aria-label={t('join_table.reject')}
                        >
                            <X className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            size="sm"
                            disabled={busy !== null}
                            onClick={() => decide(request.id, true)}
                        >
                            <Check className="h-3.5 w-3.5" />
                            {t('join_table.approve')}
                        </Button>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

/** Who is at the table; the responsible can hand the role over. */
function MembersPanel({
    code,
    members,
    isResponsible,
}: {
    code: string;
    members: Member[];
    isResponsible: boolean;
}) {
    const { t } = useTranslation();
    const [handOver, setHandOver] = useState<Member | null>(null);
    const [busy, setBusy] = useState(false);

    function confirm() {
        if (handOver === null || busy) {
            return;
        }

        setBusy(true);
        router.post(
            JoinController.transfer.url({ code, customer: handOver.id }),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setHandOver(null);
                },
            },
        );
    }

    return (
        <section className="space-y-2">
            <h2 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {t('join_table.members_title', { count: members.length })}
            </h2>
            <ul className="divide-y divide-border rounded-xl border border-border bg-card">
                {members.map((member) => (
                    <li
                        key={member.id}
                        className="flex min-h-12 items-center gap-3 px-4 py-2"
                    >
                        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold text-muted-foreground">
                            {initials(member.name)}
                        </span>
                        <span className="min-w-0 flex-1 truncate text-sm">
                            {member.name}
                            {member.is_me && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    ({t('join_table.you')})
                                </span>
                            )}
                        </span>
                        {member.is_responsible ? (
                            <span
                                className="flex items-center gap-1 text-xs font-medium text-amber-600 dark:text-amber-400"
                                title={t('join_table.responsible')}
                            >
                                <Crown className="h-3.5 w-3.5" />
                                <span className="hidden sm:inline">
                                    {t('join_table.responsible')}
                                </span>
                            </span>
                        ) : (
                            isResponsible && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="h-8 text-xs text-muted-foreground"
                                    onClick={() => setHandOver(member)}
                                >
                                    <Crown className="h-3.5 w-3.5" />
                                    {t('join_table.make_responsible')}
                                </Button>
                            )
                        )}
                    </li>
                ))}
            </ul>

            <ConfirmDialog
                open={handOver !== null}
                busy={busy}
                title={t('join_table.make_responsible')}
                description={
                    handOver
                        ? t('join_table.transfer_confirm', {
                              name: handOver.name,
                          })
                        : ''
                }
                onCancel={() => setHandOver(null)}
                onConfirm={confirm}
            />
        </section>
    );
}

/** Leaving is rare and can't be undone without approval: kept out of the way, last. */
function LeaveTable({
    code,
    members,
    isResponsible,
}: {
    code: string;
    members: Member[];
    isResponsible: boolean;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    // The role passes on in the order people joined.
    const successor = members.find((member) => !member.is_me);

    function confirm() {
        if (busy) {
            return;
        }

        setBusy(true);
        router.post(
            JoinController.leave.url(code),
            {},
            {
                onFinish: () => {
                    setBusy(false);
                    setOpen(false);
                },
            },
        );
    }

    return (
        <div className="flex justify-center pt-2">
            <Button
                size="sm"
                variant="ghost"
                className="text-destructive hover:text-destructive"
                onClick={() => setOpen(true)}
            >
                <LogOut className="h-3.5 w-3.5" />
                {t('join_table.leave')}
            </Button>

            <ConfirmDialog
                open={open}
                busy={busy}
                destructive
                title={t('join_table.leave')}
                description={
                    isResponsible && successor
                        ? t('join_table.leave_confirm_responsible', {
                              name: successor.name,
                          })
                        : t('join_table.leave_confirm')
                }
                onCancel={() => setOpen(false)}
                onConfirm={confirm}
            />
        </div>
    );
}

function ConfirmDialog({
    open,
    busy,
    destructive = false,
    title,
    description,
    onCancel,
    onConfirm,
}: {
    open: boolean;
    busy: boolean;
    destructive?: boolean;
    title: string;
    description: string;
    onCancel: () => void;
    onConfirm: () => void;
}) {
    const { t } = useTranslation();

    return (
        <Dialog open={open} onOpenChange={(o) => !o && !busy && onCancel()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        disabled={busy}
                        onClick={onCancel}
                    >
                        {t('join_table.cancel')}
                    </Button>
                    <Button
                        variant={destructive ? 'destructive' : 'default'}
                        disabled={busy}
                        onClick={onConfirm}
                    >
                        {t('join_table.confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
