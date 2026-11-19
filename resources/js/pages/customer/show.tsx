import { Head, router } from '@inertiajs/react';
import {
    Check,
    Crown,
    ImageOff,
    LogOut,
    Minus,
    Plus,
    Search,
    ShoppingBag,
    UserPlus,
    Users,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import JoinController from '@/actions/App/Http/Controllers/Customer/JoinController';
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
    has_queue: boolean;
};

type Props = {
    code: string;
    menu: MenuItem[];
    products: OrderLine[];
    preparing: PreparingItem[];
    total: number;
    paid: number;
    remaining: number;
    isResponsible: boolean;
    members: Member[];
    joinRequests: JoinRequest[];
    chat: ChatFeed;
    chatReactions: string[];
    chatMaxLength: number;
    onlinePayments: { enabled: boolean; min: number };
    payments: TablePaymentEntry[];
    myCheckout: OpenCheckout | null;
    couponQuote?: CouponQuote;
};

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

export default function CustomerShow({
    code,
    menu,
    products,
    preparing,
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
}: Props) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language === 'pt_BR' ? 'pt-BR' : 'en-US';
    const [people, setPeople] = useState(1);
    const [menuOpen, setMenuOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [activeCategory, setActiveCategory] = useState<number | null>(null);
    const [cart, setCart] = useState<Record<number, number>>({});
    const [step, setStep] = useState<'menu' | 'review'>('menu');
    const [submitting, setSubmitting] = useState(false);
    // Identifies this cart to the server: re-sending it after a timeout or a double
    // tap is ignored instead of ordering twice. A changed cart is a new order.
    const [requestKey, setRequestKey] = useState(newRequestKey);

    // Show kitchen progress, deliveries and payments without the customer reloading.
    // Paused while the menu is open so the list doesn't shift under their finger.
    useLiveReload({
        only: [
            'products',
            'preparing',
            'total',
            'paid',
            'remaining',
            'members',
            'joinRequests',
            'payments',
            'myCheckout',
            'onlinePayments',
        ],
        intervalMs: 20_000,
        enabled: !menuOpen,
    });

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

    const fmt = (n: number) => formatBRL(n, locale);

    const perPerson = people > 0 ? remaining / people : 0;

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

    function closeModal() {
        setMenuOpen(false);
        setCart({});
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
                })),
                request_key: requestKey,
            },
            {
                preserveScroll: true,
                onSuccess: () => closeModal(),
                onFinish: () => setSubmitting(false),
            },
        );
    }

    return (
        <>
            <Head title={t('join_table.title')} />

            <div className="mx-auto max-w-5xl px-4 py-6">
                <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
                    {/* Left — preparing + items on table */}
                    <div className="flex-1 space-y-6">
                        {isResponsible && (
                            <p className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                                <Crown className="h-3.5 w-3.5 text-amber-500" />
                                {t('join_table.you_are_responsible')}
                            </p>
                        )}

                        {joinRequests.length > 0 && (
                            <JoinRequestsPanel
                                code={code}
                                requests={joinRequests}
                            />
                        )}

                        <MembersPanel
                            code={code}
                            members={members}
                            isResponsible={isResponsible}
                        />

                        <TableChat
                            code={code}
                            chat={chat}
                            reactions={chatReactions}
                            maxLength={chatMaxLength}
                        />

                        <div className="flex items-center justify-between">
                            <p className="text-sm font-semibold">
                                {t('join_table.my_order')}
                            </p>
                            <Button
                                onClick={() => setMenuOpen(true)}
                                size="sm"
                                className="gap-1.5"
                            >
                                <ShoppingBag className="h-3.5 w-3.5" />
                                {t('join_table.order_button')}
                            </Button>
                        </div>

                        {preparing.some((i) => i.has_queue) && (
                            <PreparingSection
                                items={preparing.filter((i) => i.has_queue)}
                                fmt={fmt}
                                label={t('join_table.preparing_title')}
                                colorClass="amber"
                                pulse
                            />
                        )}

                        {preparing.some((i) => !i.has_queue) && (
                            <PreparingSection
                                items={preparing.filter((i) => !i.has_queue)}
                                fmt={fmt}
                                label={t('join_table.pending_delivery_title')}
                                colorClass="blue"
                            />
                        )}

                        {products.length > 0 && (
                            <div className="space-y-2">
                                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    {t('join_table.products_title')}
                                </p>
                                <div className="space-y-2">
                                    {products.map((line, i) => (
                                        <OrderCard
                                            key={i}
                                            line={line}
                                            fmt={fmt}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}

                        {preparing.length === 0 && products.length === 0 && (
                            <p className="py-12 text-center text-sm text-muted-foreground">
                                {t('join_table.no_products')}
                            </p>
                        )}
                    </div>

                    {/* Right — payment summary */}
                    <div className="flex w-full flex-col gap-4 lg:w-72 lg:shrink-0">
                        <div className="space-y-2">
                            <p className="text-sm font-semibold">
                                {t('join_table.summary_title')}
                            </p>
                            <div className="space-y-1.5 rounded-xl border border-border bg-card p-4 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        {t('join_table.total')}
                                    </span>
                                    <span className="font-medium tabular-nums">
                                        {fmt(total)}
                                    </span>
                                </div>
                                {paid > 0 && (
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            {t('join_table.paid')}
                                        </span>
                                        <span className="font-medium text-green-600 tabular-nums">
                                            {fmt(paid)}
                                        </span>
                                    </div>
                                )}
                                <div className="flex justify-between border-t border-border pt-1.5">
                                    <span className="font-semibold">
                                        {t('join_table.remaining')}
                                    </span>
                                    <span className="font-bold text-destructive tabular-nums">
                                        {fmt(remaining)}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <PayPanel
                            code={code}
                            remaining={remaining}
                            enabled={onlinePayments.enabled}
                            minimum={onlinePayments.min}
                            myCheckout={myCheckout}
                            couponQuote={couponQuote}
                        />

                        <PaymentsList payments={payments} />

                        {remaining > 0 && (
                            <div className="space-y-3 rounded-xl border border-border bg-card p-4">
                                <p className="text-sm font-semibold">
                                    {t('join_table.split_title')}
                                </p>
                                <div className="flex items-center justify-between">
                                    <span className="text-sm text-muted-foreground">
                                        {t('join_table.people')}
                                    </span>
                                    <div className="flex items-center gap-3">
                                        <button
                                            onClick={() =>
                                                setPeople((n) =>
                                                    Math.max(1, n - 1),
                                                )
                                            }
                                            disabled={people <= 1}
                                            className="flex h-8 w-8 items-center justify-center rounded-full border border-border bg-muted text-muted-foreground transition-colors hover:bg-muted/80 disabled:opacity-40"
                                        >
                                            <Minus className="h-3.5 w-3.5" />
                                        </button>
                                        <span className="w-6 text-center text-lg font-bold tabular-nums">
                                            {people}
                                        </span>
                                        <button
                                            onClick={() =>
                                                setPeople((n) => n + 1)
                                            }
                                            className="flex h-8 w-8 items-center justify-center rounded-full border border-border bg-muted text-muted-foreground transition-colors hover:bg-muted/80"
                                        >
                                            <Plus className="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </div>
                                <div className="flex items-baseline justify-between rounded-lg bg-muted px-3 py-2">
                                    <span className="text-sm text-muted-foreground">
                                        {t('join_table.each_pays')}
                                    </span>
                                    <span className="text-xl font-bold tabular-nums">
                                        {fmt(perPerson)}
                                    </span>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Order modal */}
            <Dialog
                open={menuOpen}
                onOpenChange={(open) => {
                    if (!open) {
                        closeModal();
                    } else {
                        setMenuOpen(true);
                    }
                }}
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
                            {/* Search + category filters */}
                            <div className="shrink-0 space-y-3 border-b border-border px-4 py-3">
                                <div className="relative">
                                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        placeholder={t(
                                            'join_table.search_placeholder',
                                        )}
                                        value={search}
                                        onChange={(e) =>
                                            setSearch(e.target.value)
                                        }
                                        className="pl-9"
                                    />
                                </div>

                                {categories.length > 0 && (
                                    <div className="flex scrollbar-none gap-1.5 overflow-x-auto pb-0.5">
                                        <button
                                            onClick={() =>
                                                setActiveCategory(null)
                                            }
                                            className={cn(
                                                'shrink-0 rounded-full px-3 py-1 text-xs font-medium transition-colors',
                                                activeCategory === null
                                                    ? 'bg-primary text-primary-foreground'
                                                    : 'bg-muted text-muted-foreground hover:bg-muted/80',
                                            )}
                                        >
                                            {t('join_table.all_categories')}
                                        </button>
                                        {categories.map((cat) => (
                                            <button
                                                key={cat.id}
                                                onClick={() =>
                                                    setActiveCategory(cat.id)
                                                }
                                                className={cn(
                                                    'shrink-0 rounded-full px-3 py-1 text-xs font-medium transition-colors',
                                                    activeCategory === cat.id
                                                        ? 'bg-primary text-primary-foreground'
                                                        : 'bg-muted text-muted-foreground hover:bg-muted/80',
                                                )}
                                            >
                                                {cat.name}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {/* Product list */}
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

                            {/* Next footer */}
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
                            {/* Review list */}
                            <div className="flex-1 overflow-y-auto">
                                <div className="divide-y divide-border">
                                    {cartLines.map(({ item, quantity }) => (
                                        <ReviewRow
                                            key={item.id}
                                            item={item}
                                            fmt={fmt}
                                            quantity={quantity}
                                            onIncrement={() =>
                                                increment(item.id)
                                            }
                                            onDecrement={() =>
                                                decrement(item.id)
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

                            {/* Confirm footer */}
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
        </>
    );
}

CustomerShow.layout = {
    breadcrumbs: [
        { title: 'join_table.title', href: JoinController.index.url() },
    ],
};

function OrderCard({
    line,
    fmt,
}: {
    line: OrderLine;
    fmt: (n: number) => string;
}) {
    const suffix: Record<string, string> = {
        unit: 'un.',
        kg: 'kg',
        '100g': '100g',
        liter: 'L',
        portion: 'porção',
    };

    return (
        <div className="flex gap-3 rounded-xl border border-border bg-card p-3">
            <div className="h-16 w-16 shrink-0 overflow-hidden rounded-lg bg-muted">
                {line.picture_url ? (
                    <img
                        loading="lazy"
                        decoding="async"
                        src={line.picture_url}
                        alt={line.name}
                        className="h-full w-full object-cover"
                    />
                ) : (
                    <div className="flex h-full w-full items-center justify-center text-muted-foreground">
                        <ImageOff className="h-5 w-5 opacity-30" />
                    </div>
                )}
            </div>

            <div className="flex min-w-0 flex-1 flex-col justify-between">
                <div>
                    <p className="leading-snug font-medium">{line.name}</p>
                    {line.description && (
                        <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                            {line.description}
                        </p>
                    )}
                    {line.ordered_by && (
                        <p className="mt-0.5 text-xs text-muted-foreground/70">
                            {line.ordered_by}
                        </p>
                    )}
                </div>
                <div className="flex items-baseline justify-between">
                    <p className="text-sm font-semibold text-primary">
                        {fmt(line.price)}
                        <span className="ml-1 text-xs font-normal text-muted-foreground">
                            / {suffix[line.price_type] ?? line.price_type}
                        </span>
                    </p>
                    <span className="text-xs text-muted-foreground">
                        × {line.quantity}
                    </span>
                </div>
            </div>
        </div>
    );
}

function PreparingSection({
    items,
    fmt,
    label,
    colorClass,
    pulse = false,
}: {
    items: PreparingItem[];
    fmt: (n: number) => string;
    label: string;
    colorClass: 'amber' | 'blue';
    pulse?: boolean;
}) {
    const colors = {
        amber: {
            dot: 'bg-amber-500',
            ping: 'bg-amber-400',
            label: 'text-amber-600 dark:text-amber-400',
            border: 'border-amber-200 dark:border-amber-900/50',
            bg: 'bg-amber-50 dark:bg-amber-950/20',
            divide: 'divide-amber-100 dark:divide-amber-900/40',
            qty: 'text-amber-500',
            by: 'text-amber-500/80',
            price: 'text-amber-700 dark:text-amber-300',
        },
        blue: {
            dot: 'bg-blue-500',
            ping: 'bg-blue-400',
            label: 'text-blue-600 dark:text-blue-400',
            border: 'border-blue-200 dark:border-blue-900/50',
            bg: 'bg-blue-50 dark:bg-blue-950/20',
            divide: 'divide-blue-100 dark:divide-blue-900/40',
            qty: 'text-blue-500',
            by: 'text-blue-500/80',
            price: 'text-blue-700 dark:text-blue-300',
        },
    }[colorClass];

    return (
        <div className="space-y-2">
            <div className="flex items-center gap-2">
                <span className="relative flex h-2 w-2 shrink-0">
                    {pulse && (
                        <span
                            className={`absolute inline-flex h-full w-full animate-ping rounded-full ${colors.ping} opacity-75`}
                        />
                    )}
                    <span
                        className={`relative inline-flex h-2 w-2 rounded-full ${colors.dot}`}
                    />
                </span>
                <p
                    className={`text-xs font-semibold tracking-wide uppercase ${colors.label}`}
                >
                    {label}
                </p>
            </div>
            <div
                className={`divide-y ${colors.divide} rounded-xl border ${colors.border} ${colors.bg}`}
            >
                {items.map((item, i) => (
                    <div
                        key={i}
                        className="flex items-center justify-between px-4 py-2.5 text-sm"
                    >
                        <div>
                            <span>
                                {item.name}
                                {item.quantity > 1 && (
                                    <span
                                        className={`ml-1 text-xs ${colors.qty}`}
                                    >
                                        ×{item.quantity}
                                    </span>
                                )}
                            </span>
                            {item.ordered_by && (
                                <p className={`text-xs ${colors.by}`}>
                                    {item.ordered_by}
                                </p>
                            )}
                        </div>
                        <span
                            className={`font-medium tabular-nums ${colors.price}`}
                        >
                            {fmt(item.price)}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function ReviewRow({
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
        <div className="flex items-center gap-3 px-4 py-3">
            <div className="h-12 w-12 shrink-0 overflow-hidden rounded-lg bg-muted">
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

            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{item.name}</p>
                <p className="text-xs font-semibold text-primary tabular-nums">
                    {fmt(item.price * quantity)}
                </p>
            </div>

            <div className="flex shrink-0 items-center gap-1.5">
                <button
                    onClick={onDecrement}
                    className="flex h-7 w-7 items-center justify-center rounded-full border border-border bg-muted text-muted-foreground transition-colors hover:bg-muted/80 active:scale-95"
                >
                    <Minus className="h-3.5 w-3.5" />
                </button>
                <span className="w-5 text-center text-sm font-bold tabular-nums">
                    {quantity}
                </span>
                <button
                    onClick={onIncrement}
                    className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-primary-foreground transition-opacity hover:opacity-90 active:scale-95"
                >
                    <Plus className="h-3.5 w-3.5" />
                </button>
            </div>
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
    const suffix: Record<string, string> = {
        unit: 'un.',
        kg: 'kg',
        '100g': '100g',
        liter: 'L',
        portion: 'porção',
    };

    return (
        <div className="flex items-center gap-3 px-4 py-3">
            <div className="h-12 w-12 shrink-0 overflow-hidden rounded-lg bg-muted">
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

            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{item.name}</p>
                {item.description && (
                    <p className="truncate text-xs text-muted-foreground">
                        {item.description}
                    </p>
                )}
                <p className="text-xs font-semibold text-primary">
                    {fmt(item.price)}
                    <span className="ml-1 font-normal text-muted-foreground">
                        / {suffix[item.price_type] ?? item.price_type}
                    </span>
                </p>
            </div>

            {quantity === 0 ? (
                <button
                    onClick={onIncrement}
                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground transition-opacity hover:opacity-90 active:scale-95"
                >
                    <Plus className="h-4 w-4" />
                </button>
            ) : (
                <div className="flex shrink-0 items-center gap-1.5">
                    <button
                        onClick={onDecrement}
                        className="flex h-7 w-7 items-center justify-center rounded-full border border-border bg-muted text-muted-foreground transition-colors hover:bg-muted/80 active:scale-95"
                    >
                        <Minus className="h-3.5 w-3.5" />
                    </button>
                    <span className="w-5 text-center text-sm font-bold tabular-nums">
                        {quantity}
                    </span>
                    <button
                        onClick={onIncrement}
                        className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-primary-foreground transition-opacity hover:opacity-90 active:scale-95"
                    >
                        <Plus className="h-3.5 w-3.5" />
                    </button>
                </div>
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
        <div className="space-y-2 rounded-xl border border-primary/40 bg-primary/5 p-4">
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
                            <span className="hidden sm:inline">
                                {t('join_table.reject')}
                            </span>
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

type PendingAction =
    | { kind: 'transfer'; member: Member }
    | { kind: 'leave' }
    | null;

/** Who is at the table; the responsible hands the role over, anyone can leave. */
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
    const [pending, setPending] = useState<PendingAction>(null);
    const [busy, setBusy] = useState(false);
    // The role passes on in the order people joined.
    const successor = members.find((member) => !member.is_me);

    function confirm() {
        if (pending === null || busy) {
            return;
        }

        const url =
            pending.kind === 'transfer'
                ? JoinController.transfer.url({
                      code,
                      customer: pending.member.id,
                  })
                : JoinController.leave.url(code);

        setBusy(true);
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setPending(null);
                },
            },
        );
    }

    let description = '';

    if (pending?.kind === 'transfer') {
        description = t('join_table.transfer_confirm', {
            name: pending.member.name,
        });
    } else if (pending?.kind === 'leave') {
        description =
            isResponsible && successor
                ? t('join_table.leave_confirm_responsible', {
                      name: successor.name,
                  })
                : t('join_table.leave_confirm');
    }

    return (
        <div className="space-y-2 rounded-xl border border-border bg-card p-4">
            <p className="flex items-center gap-2 text-sm font-semibold">
                <Users className="h-4 w-4 text-muted-foreground" />
                {t('join_table.members_title', { count: members.length })}
            </p>
            <ul className="divide-y divide-border">
                {members.map((member) => (
                    <li
                        key={member.id}
                        className="flex min-h-11 items-center gap-2 py-1.5"
                    >
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
                            <span className="flex items-center gap-1 text-xs font-medium text-amber-600">
                                <Crown className="h-3.5 w-3.5" />
                                {t('join_table.responsible')}
                            </span>
                        ) : (
                            isResponsible && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        setPending({ kind: 'transfer', member })
                                    }
                                >
                                    <Crown className="h-3.5 w-3.5" />
                                    {t('join_table.make_responsible')}
                                </Button>
                            )
                        )}
                    </li>
                ))}
            </ul>
            <Button
                size="sm"
                variant="ghost"
                className="w-full text-destructive hover:text-destructive"
                onClick={() => setPending({ kind: 'leave' })}
            >
                <LogOut className="h-3.5 w-3.5" />
                {t('join_table.leave')}
            </Button>

            <Dialog
                open={pending !== null}
                onOpenChange={(open) => !open && !busy && setPending(null)}
            >
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>
                            {pending?.kind === 'transfer'
                                ? t('join_table.make_responsible')
                                : t('join_table.leave')}
                        </DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => setPending(null)}
                        >
                            {t('join_table.cancel')}
                        </Button>
                        <Button
                            variant={
                                pending?.kind === 'leave'
                                    ? 'destructive'
                                    : 'default'
                            }
                            disabled={busy}
                            onClick={confirm}
                        >
                            {t('join_table.confirm')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
