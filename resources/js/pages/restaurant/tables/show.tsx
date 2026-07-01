import { Form, Head, Link, router } from '@inertiajs/react';
import { Banknote, CreditCard, Clock, ImageOff, Minus, Plus, QrCode, Search, ShoppingCart, Tag, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import QueueItemController from '@/actions/App/Http/Controllers/Restaurant/QueueItemController';
import TableController from '@/actions/App/Http/Controllers/Restaurant/TableController';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { PaymentMethod, Product, QueueItem, RestaurantTable, TablePayment } from '@/types';

type AvailableProduct = Pick<Product, 'id' | 'name' | 'picture_url' | 'price' | 'price_type' | 'queue_id'> & {
    queue: { id: number; name: string } | null;
};

type Props = {
    table: RestaurantTable;
    products: AvailableProduct[];
    queueItems: QueueItem[];
};

const fmt = (n: number) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(n);

const PAYMENT_ICONS: Record<PaymentMethod, React.ReactNode> = {
    cash:   <Banknote className="h-4 w-4" />,
    pix:    <QrCode className="h-4 w-4" />,
    card:   <CreditCard className="h-4 w-4" />,
    coupon: <Tag className="h-4 w-4" />,
};

const PAYMENT_METHODS: PaymentMethod[] = ['cash', 'pix', 'card', 'coupon'];

// ─── Main page ────────────────────────────────────────────────────────────────

export default function ManageOrder({ table, products, queueItems }: Props) {
    const { t } = useTranslation();

    const initial: Record<number, number> = {};
    for (const item of table.products) initial[item.id] = item.pivot.quantity;

    const [quantities, setQuantities] = useState<Record<number, number>>(initial);

    // Sync local quantities with server state whenever table.products actually changes
    // (e.g. after a queue item is delivered and appears in the order).
    const productsKey = table.products.map((p) => `${p.id}:${p.pivot.quantity}`).join(',');
    useEffect(() => {
        const next: Record<number, number> = {};
        for (const item of table.products) next[item.id] = item.pivot.quantity;
        setQuantities(next);
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [productsKey]);
    const [addProductsOpen, setAddProductsOpen] = useState(false);
    const [addPaymentOpen, setAddPaymentOpen] = useState(false);
    const [autoSubmitting, setAutoSubmitting] = useState(false);

    const adjust = (id: number, delta: number) =>
        setQuantities((prev) => {
            const next = Math.max(0, (prev[id] ?? 0) + delta);
            const updated = { ...prev };
            if (next === 0) delete updated[id];
            else updated[id] = next;
            return updated;
        });

    const handleAddProducts = (pending: Record<number, number>) => {
        // Split pending into direct-order (non-queue) and queue additions
        const merged = { ...quantities };
        const queueAdditions: Record<number, number> = {};

        for (const [rawId, qty] of Object.entries(pending)) {
            const id = Number(rawId);
            if (qty <= 0) continue;
            const product = products.find((p) => p.id === id);
            if (product?.queue_id) {
                queueAdditions[id] = qty;
            } else {
                merged[id] = (merged[id] ?? 0) + qty;
            }
        }

        setAutoSubmitting(true);
        setQuantities(merged);
        setAddProductsOpen(false);
        router.patch(
            TableController.update.url({ table: table.id }),
            { products: merged, queue_additions: queueAdditions },
            { onFinish: () => setAutoSubmitting(false) },
        );
    };

    const isDirty = useMemo(() => {
        const saved: Record<number, number> = {};
        for (const item of table.products) saved[item.id] = item.pivot.quantity;
        const keys = new Set([...Object.keys(quantities), ...Object.keys(saved)].map(Number));
        for (const id of keys) {
            if ((quantities[id] ?? 0) !== (saved[id] ?? 0)) return true;
        }
        return false;
    }, [quantities, table.products]);

    const orderItems = products.filter((p) => (quantities[p.id] ?? 0) > 0);
    const productTotal = orderItems.reduce((sum, p) => sum + p.price * quantities[p.id], 0);
    const paymentTotal = table.payments.reduce((sum, p) => sum + p.amount, 0);
    const remaining = productTotal - paymentTotal;
    const canClose = productTotal > 0 && paymentTotal >= productTotal;

    return (
        <>
            <Head title={table.title} />

            <AddProductsDialog
                open={addProductsOpen}
                products={products}
                onConfirm={handleAddProducts}
                onClose={() => setAddProductsOpen(false)}
            />

            <AddPaymentDialog
                open={addPaymentOpen}
                table={table}
                remaining={remaining}
                onClose={() => setAddPaymentOpen(false)}
            />

            <div className="mx-auto max-w-4xl space-y-6 p-6">
                <div>
                    <h1 className="text-xl font-semibold">{table.title}</h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">{t('tables.manage_products')}</p>
                </div>

                {/* ── Queue items section ── */}
                {queueItems.length > 0 && (
                    <div className="space-y-3">
                        <p className="text-sm font-semibold">{t('queues.items_title')}</p>
                        <div className="overflow-hidden rounded-xl border border-border">
                            {queueItems.map((item, idx) => (
                                <div
                                    key={item.id}
                                    className={[
                                        'flex items-center gap-3 px-4 py-3',
                                        idx > 0 ? 'border-t border-border' : '',
                                    ].join(' ')}
                                >
                                    <div className="h-10 w-10 shrink-0 overflow-hidden rounded-lg bg-muted">
                                        {item.product.picture_url ? (
                                            <img src={item.product.picture_url} alt={item.product.name} className="h-full w-full object-cover" />
                                        ) : (
                                            <div className="flex h-full w-full items-center justify-center text-muted-foreground">
                                                <ImageOff className="h-3.5 w-3.5 opacity-40" />
                                            </div>
                                        )}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">{item.product.name}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {item.queue.name} · x{item.quantity}
                                        </p>
                                    </div>
                                    {item.status === 'pending' ? (
                                        <Form {...QueueItemController.markDone.form({ queueItem: item.id })}>
                                            {({ processing }) => (
                                                <Button type="submit" size="sm" variant="outline" disabled={processing} className="shrink-0">
                                                    <Clock className="mr-1.5 h-3.5 w-3.5" />
                                                    {t('queues.item_mark_done')}
                                                </Button>
                                            )}
                                        </Form>
                                    ) : (
                                        <Form {...QueueItemController.markDelivered.form({ queueItem: item.id })}>
                                            {({ processing }) => (
                                                <Button type="submit" size="sm" disabled={processing} className="shrink-0">
                                                    {t('queues.item_mark_delivered')}
                                                </Button>
                                            )}
                                        </Form>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                <div className="grid grid-cols-1 items-start gap-6 md:grid-cols-2">

                {/* ── Products form ── */}
                <div className="space-y-3">
                <p className="text-sm font-semibold">{t('products.title')}</p>
                <Form
                    {...TableController.update.form({ table: table.id })}
                    className="space-y-3"
                >
                    {({ processing }) => (
                        <>
                            {Object.entries(quantities).map(([id, qty]) => (
                                <input key={id} type="hidden" name={`products[${id}]`} value={qty} />
                            ))}

                            {orderItems.length > 0 ? (
                                <div className="overflow-hidden rounded-xl border border-border">
                                    {orderItems.map((product, idx) => {
                                        const qty = quantities[product.id];
                                        const suffix = t(`products.price_suffix.${product.price_type}`);
                                        return (
                                            <div
                                                key={product.id}
                                                className={[
                                                    'flex items-center gap-3 px-4 py-3',
                                                    idx > 0 ? 'border-t border-border' : '',
                                                ].join(' ')}
                                            >
                                                <div className="h-10 w-10 shrink-0 overflow-hidden rounded-lg bg-muted">
                                                    {product.picture_url ? (
                                                        <img src={product.picture_url} alt={product.name} className="h-full w-full object-cover" />
                                                    ) : (
                                                        <div className="flex h-full w-full items-center justify-center text-muted-foreground">
                                                            <ImageOff className="h-3.5 w-3.5 opacity-40" />
                                                        </div>
                                                    )}
                                                </div>
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-medium">{product.name}</p>
                                                    <p className="text-xs text-muted-foreground">{fmt(product.price)} / {suffix}</p>
                                                </div>
                                                <p className="w-20 shrink-0 text-right text-sm font-semibold tabular-nums">
                                                    {fmt(product.price * qty)}
                                                </p>
                                                <div className="flex shrink-0 items-center gap-1">
                                                    <Button type="button" variant="outline" size="icon" className="h-7 w-7" onClick={() => adjust(product.id, -1)}>
                                                        <Minus className="h-3 w-3" />
                                                    </Button>
                                                    <span className="w-6 text-center text-sm tabular-nums">{qty}</span>
                                                    <Button type="button" variant="outline" size="icon" className="h-7 w-7" onClick={() => adjust(product.id, +1)}>
                                                        <Plus className="h-3 w-3" />
                                                    </Button>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border py-8 text-muted-foreground">
                                    <ShoppingCart className="h-7 w-7 opacity-30" />
                                    <p className="text-sm">{t('tables.order_empty')}</p>
                                </div>
                            )}

                            <Button type="button" variant="outline" className="w-full" onClick={() => setAddProductsOpen(true)}>
                                <Plus className="mr-2 h-4 w-4" />
                                {t('tables.add_products')}
                            </Button>

                            <div className="flex items-center justify-between rounded-xl border border-border px-5 py-3">
                                <span className="text-sm text-muted-foreground">{t('tables.order_total')}</span>
                                <span className="text-lg font-bold tabular-nums">{orderItems.length > 0 ? fmt(productTotal) : '—'}</span>
                            </div>

                            <Button type="submit" disabled={processing || !isDirty || autoSubmitting} variant="outline" className="w-full">
                                {t('tables.save_order')}
                            </Button>
                        </>
                    )}
                </Form>
                </div>

                {/* ── Payments section ── */}
                <div className="space-y-3">
                    <p className="text-sm font-semibold">{t('tables.payments_title')}</p>

                    {table.payments.length > 0 ? (
                        <div className="overflow-hidden rounded-xl border border-border">
                            {table.payments.map((payment, idx) => (
                                <PaymentRow key={payment.id} payment={payment} idx={idx} />
                            ))}
                        </div>
                    ) : (
                        <p className="rounded-xl border border-dashed border-border px-5 py-4 text-sm text-muted-foreground">
                            {t('tables.payments_empty')}
                        </p>
                    )}

                    {table.payments.length > 0 && (
                        <div className="space-y-1 rounded-xl border border-border px-5 py-3 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">{t('tables.payment_total_paid')}</span>
                                <span className="font-semibold tabular-nums text-green-600">{fmt(paymentTotal)}</span>
                            </div>
                            {remaining > 0 && (
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">{t('tables.payment_remaining')}</span>
                                    <span className="font-semibold tabular-nums text-destructive">{fmt(remaining)}</span>
                                </div>
                            )}
                        </div>
                    )}

                    <Button type="button" variant="outline" className="w-full" onClick={() => setAddPaymentOpen(true)}>
                        <Plus className="mr-2 h-4 w-4" />
                        {t('tables.payment_add')}
                    </Button>
                </div>

                </div>{/* ── end grid ── */}

                {/* ── Close table ── */}
                <div className="space-y-2 border-t border-border pt-4">
                    {!canClose && productTotal > 0 && (
                        <p className="text-xs text-muted-foreground">{t('tables.close_table_hint')}</p>
                    )}
                    <Form
                        {...TableController.close.form({ table: table.id })}
                        onBefore={() => confirm(t('tables.close_table_confirm'))}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                disabled={!canClose || processing}
                                className="w-full"
                            >
                                <X className="mr-2 h-4 w-4" />
                                {t('tables.close_table')}
                            </Button>
                        )}
                    </Form>
                    <Button variant="ghost" size="sm" className="w-full" asChild>
                        <Link href={TableController.index.url()}>{t('tables.cancel')}</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}

ManageOrder.layout = {
    breadcrumbs: [
        { title: 'tables.breadcrumb_index', href: TableController.index.url() },
        { title: 'tables.manage_products', href: '#' },
    ],
};

// ─── Payment row ──────────────────────────────────────────────────────────────

function PaymentRow({ payment, idx }: { payment: TablePayment; idx: number }) {
    const { t } = useTranslation();
    return (
        <div className={['flex items-center gap-3 px-4 py-3', idx > 0 ? 'border-t border-border' : ''].join(' ')}>
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                {PAYMENT_ICONS[payment.method]}
            </span>
            <div className="min-w-0 flex-1">
                <span className="text-sm">{t(`tables.payment_methods.${payment.method}`)}</span>
                {payment.registered_by && (
                    <p className="text-xs text-muted-foreground">
                        {t('tables.payment_by', { name: payment.registered_by.name })}
                    </p>
                )}
            </div>
            <span className="text-sm font-semibold tabular-nums">{fmt(payment.amount)}</span>
        </div>
    );
}

// ─── Add payment dialog ───────────────────────────────────────────────────────

function AddPaymentDialog({ open, table, remaining, onClose }: {
    open: boolean;
    table: RestaurantTable;
    remaining: number;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [cents, setCents] = useState(0);

    useEffect(() => {
        if (open) setCents(remaining > 0 ? Math.round(remaining * 100) : 0);
    }, [open, remaining]);

    const displayValue = cents > 0
        ? (cents / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : '';

    const handleAmountChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const digits = e.target.value.replace(/\D/g, '');
        setCents(digits === '' ? 0 : Math.min(parseInt(digits, 10), 9999999));
    };

    return (
        <Dialog open={open} onOpenChange={(o) => { if (!o) onClose(); }}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>{t('tables.payment_add')}</DialogTitle>
                </DialogHeader>

                <Form
                    {...TableController.addPayment.form({ table: table.id })}
                    className="space-y-4"
                >
                    {({ processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="method">{t('tables.payment_method_label')}</Label>
                                <Select name="method" defaultValue="cash">
                                    <SelectTrigger id="method">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {PAYMENT_METHODS.map((m) => (
                                            <SelectItem key={m} value={m}>
                                                <span className="flex items-center gap-2">
                                                    {PAYMENT_ICONS[m]}
                                                    {t(`tables.payment_methods.${m}`)}
                                                </span>
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="amount">{t('tables.payment_amount_label')}</Label>
                                <input type="hidden" name="amount" value={(cents / 100).toFixed(2)} />
                                <Input
                                    id="amount"
                                    type="text"
                                    inputMode="numeric"
                                    value={displayValue}
                                    onChange={handleAmountChange}
                                    autoFocus
                                    placeholder="0,00"
                                />
                            </div>

                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={onClose}>
                                    {t('tables.cancel')}
                                </Button>
                                <Button type="submit" disabled={processing || cents === 0}>
                                    {t('tables.confirm_add')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

// ─── Add products dialog ──────────────────────────────────────────────────────

type DialogProps = {
    open: boolean;
    products: AvailableProduct[];
    onConfirm: (pending: Record<number, number>) => void;
    onClose: () => void;
};

function AddProductsDialog({ open, products, onConfirm, onClose }: DialogProps) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');
    const [pending, setPending] = useState<Record<number, number>>({});

    const filtered = products.filter((p) =>
        p.name.toLowerCase().includes(search.toLowerCase()),
    );

    const totalSelected = Object.values(pending).reduce((a, b) => a + b, 0);

    const setPendingQty = (id: number, delta: number) =>
        setPending((prev) => {
            const next = Math.max(0, (prev[id] ?? 0) + delta);
            const updated = { ...prev };
            if (next === 0) delete updated[id];
            else updated[id] = next;
            return updated;
        });

    const reset = () => { setSearch(''); setPending({}); };

    const handleConfirm = () => { onConfirm(pending); reset(); };
    const handleClose = () => { reset(); onClose(); };

    return (
        <Dialog open={open} onOpenChange={(o) => { if (!o) handleClose(); }}>
            <DialogContent className="flex max-h-[85vh] max-w-lg flex-col gap-4 p-0">
                <DialogHeader className="px-6 pt-6">
                    <DialogTitle>{t('tables.add_products')}</DialogTitle>
                </DialogHeader>

                <div className="px-6">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            className="pl-9"
                            placeholder={t('tables.search_placeholder')}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            autoFocus
                        />
                    </div>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto border-y border-border">
                    {filtered.length === 0 ? (
                        <p className="py-10 text-center text-sm text-muted-foreground">{t('tables.no_products_hint')}</p>
                    ) : filtered.map((product, idx) => {
                        const qty = pending[product.id] ?? 0;
                        const suffix = t(`products.price_suffix.${product.price_type}`);
                        return (
                            <div
                                key={product.id}
                                className={[
                                    'flex items-center gap-3 px-6 py-3',
                                    idx > 0 ? 'border-t border-border' : '',
                                    qty > 0 ? 'bg-primary/5' : '',
                                ].join(' ')}
                            >
                                <div className="h-10 w-10 shrink-0 overflow-hidden rounded-lg bg-muted">
                                    {product.picture_url ? (
                                        <img src={product.picture_url} alt={product.name} className="h-full w-full object-cover" />
                                    ) : (
                                        <div className="flex h-full w-full items-center justify-center text-muted-foreground">
                                            <ImageOff className="h-3.5 w-3.5 opacity-40" />
                                        </div>
                                    )}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">{product.name}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {fmt(product.price)} / {suffix}
                                        {product.queue_id && (
                                            <span className="ml-1.5 rounded bg-muted px-1 py-0.5 text-[10px] font-medium">
                                                {product.queue?.name}
                                            </span>
                                        )}
                                    </p>
                                </div>
                                {qty === 0 ? (
                                    <Button type="button" size="sm" variant="outline" onClick={() => setPendingQty(product.id, 1)}>
                                        <Plus className="mr-1 h-3.5 w-3.5" />
                                        {t('tables.item_add')}
                                    </Button>
                                ) : (
                                    <div className="flex items-center gap-1">
                                        <Button type="button" variant="outline" size="icon" className="h-8 w-8" onClick={() => setPendingQty(product.id, -1)}>
                                            <Minus className="h-3.5 w-3.5" />
                                        </Button>
                                        <span className="w-6 text-center text-sm tabular-nums">{qty}</span>
                                        <Button type="button" variant="outline" size="icon" className="h-8 w-8" onClick={() => setPendingQty(product.id, +1)}>
                                            <Plus className="h-3.5 w-3.5" />
                                        </Button>
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>

                <DialogFooter className="px-6 pb-6">
                    <Button variant="outline" onClick={handleClose}>{t('tables.cancel')}</Button>
                    <Button onClick={handleConfirm} disabled={totalSelected === 0}>
                        {t('tables.confirm_add')}
                        {totalSelected > 0 && (
                            <span className="ml-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-white/20 text-xs tabular-nums">
                                {totalSelected}
                            </span>
                        )}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
