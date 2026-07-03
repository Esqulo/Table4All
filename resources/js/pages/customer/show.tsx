import { Head, router } from '@inertiajs/react';
import { ImageOff, Minus, Plus, ShoppingBag } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import JoinController from '@/actions/App/Http/Controllers/Customer/JoinController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type MenuItem = {
    id: number;
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
};

type PreparingItem = { name: string; price: number; quantity: number };

type Props = {
    code: string;
    menu: MenuItem[];
    products: OrderLine[];
    preparing: PreparingItem[];
    total: number;
    paid: number;
    remaining: number;
};

export default function CustomerShow({ code, menu, products, preparing, total, paid, remaining }: Props) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language === 'pt_BR' ? 'pt-BR' : 'en-US';
    const [people, setPeople] = useState(1);
    const [menuOpen, setMenuOpen] = useState(false);

    const fmt = (n: number) =>
        new Intl.NumberFormat(locale, { style: 'currency', currency: 'BRL' }).format(n);

    const perPerson = people > 0 ? remaining / people : 0;

    function addToOrder(productId: number) {
        router.post(
            JoinController.order.url(code),
            { product_id: productId },
            { preserveScroll: true, preserveState: true },
        );
    }

    return (
        <>
            <Head title={t('join_table.title')} />

            <div className="mx-auto max-w-5xl px-4 py-6">
                <div className="flex flex-col gap-6 lg:flex-row lg:items-start">

                    {/* Left — preparing + items on table */}
                    <div className="flex-1 space-y-6">

                        <div className="flex items-center justify-between">
                            <p className="text-sm font-semibold">{t('join_table.my_order')}</p>
                            <Button onClick={() => setMenuOpen(true)} size="sm" className="gap-1.5">
                                <ShoppingBag className="h-3.5 w-3.5" />
                                {t('join_table.order_button')}
                            </Button>
                        </div>

                        {preparing.length > 0 && (
                            <div className="space-y-2">
                                <div className="flex items-center gap-2">
                                    <span className="relative flex h-2 w-2 shrink-0">
                                        <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75" />
                                        <span className="relative inline-flex h-2 w-2 rounded-full bg-amber-500" />
                                    </span>
                                    <p className="text-xs font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400">
                                        {t('join_table.preparing_title')}
                                    </p>
                                </div>
                                <div className="divide-y divide-amber-100 rounded-xl border border-amber-200 bg-amber-50 dark:divide-amber-900/40 dark:border-amber-900/50 dark:bg-amber-950/20">
                                    {preparing.map((item, i) => (
                                        <div key={i} className="flex items-center justify-between px-4 py-2.5 text-sm">
                                            <span>
                                                {item.name}
                                                {item.quantity > 1 && (
                                                    <span className="ml-1 text-xs text-amber-500">×{item.quantity}</span>
                                                )}
                                            </span>
                                            <span className="font-medium tabular-nums text-amber-700 dark:text-amber-300">
                                                {fmt(item.price)}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {products.length > 0 && (
                            <div className="space-y-2">
                                <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    {t('join_table.products_title')}
                                </p>
                                <div className="space-y-2">
                                    {products.map((line, i) => (
                                        <OrderCard key={i} line={line} fmt={fmt} />
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
                            <p className="text-sm font-semibold">{t('join_table.summary_title')}</p>
                            <div className="rounded-xl border border-border bg-card p-4 space-y-1.5 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">{t('join_table.total')}</span>
                                    <span className="font-medium tabular-nums">{fmt(total)}</span>
                                </div>
                                {paid > 0 && (
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">{t('join_table.paid')}</span>
                                        <span className="font-medium tabular-nums text-green-600">{fmt(paid)}</span>
                                    </div>
                                )}
                                <div className="flex justify-between border-t border-border pt-1.5">
                                    <span className="font-semibold">{t('join_table.remaining')}</span>
                                    <span className="font-bold tabular-nums text-destructive">{fmt(remaining)}</span>
                                </div>
                            </div>
                        </div>

                        {remaining > 0 && (
                            <div className="rounded-xl border border-border bg-card p-4 space-y-3">
                                <p className="text-sm font-semibold">{t('join_table.split_title')}</p>
                                <div className="flex items-center justify-between">
                                    <span className="text-sm text-muted-foreground">{t('join_table.people')}</span>
                                    <div className="flex items-center gap-3">
                                        <button
                                            onClick={() => setPeople((n) => Math.max(1, n - 1))}
                                            disabled={people <= 1}
                                            className="flex h-8 w-8 items-center justify-center rounded-full border border-border bg-muted text-muted-foreground transition-colors hover:bg-muted/80 disabled:opacity-40"
                                        >
                                            <Minus className="h-3.5 w-3.5" />
                                        </button>
                                        <span className="w-6 text-center text-lg font-bold tabular-nums">{people}</span>
                                        <button
                                            onClick={() => setPeople((n) => n + 1)}
                                            className="flex h-8 w-8 items-center justify-center rounded-full border border-border bg-muted text-muted-foreground transition-colors hover:bg-muted/80"
                                        >
                                            <Plus className="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </div>
                                <div className="flex items-baseline justify-between rounded-lg bg-muted px-3 py-2">
                                    <span className="text-sm text-muted-foreground">{t('join_table.each_pays')}</span>
                                    <span className="text-xl font-bold tabular-nums">{fmt(perPerson)}</span>
                                </div>
                            </div>
                        )}

                    </div>
                </div>
            </div>

            {/* Order modal */}
            <Dialog open={menuOpen} onOpenChange={setMenuOpen}>
                <DialogContent className="flex max-h-[85vh] flex-col gap-0 p-0 sm:max-w-lg">
                    <DialogHeader className="shrink-0 border-b border-border px-6 py-4">
                        <DialogTitle>{t('join_table.menu_title')}</DialogTitle>
                    </DialogHeader>
                    <div className="flex-1 overflow-y-auto">
                        {menu.length === 0 ? (
                            <p className="py-12 text-center text-sm text-muted-foreground">
                                {t('join_table.no_products')}
                            </p>
                        ) : (
                            <div className="divide-y divide-border">
                                {menu.map((item) => (
                                    <MenuRow key={item.id} item={item} fmt={fmt} onAdd={addToOrder} />
                                ))}
                            </div>
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

CustomerShow.layout = {
    breadcrumbs: [{ title: 'join_table.title', href: JoinController.index.url() }],
};

function OrderCard({ line, fmt }: { line: OrderLine; fmt: (n: number) => string }) {
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
                    <img src={line.picture_url} alt={line.name} className="h-full w-full object-cover" />
                ) : (
                    <div className="flex h-full w-full items-center justify-center text-muted-foreground">
                        <ImageOff className="h-5 w-5 opacity-30" />
                    </div>
                )}
            </div>

            <div className="flex min-w-0 flex-1 flex-col justify-between">
                <div>
                    <p className="font-medium leading-snug">{line.name}</p>
                    {line.description && (
                        <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">{line.description}</p>
                    )}
                </div>
                <div className="flex items-baseline justify-between">
                    <p className="text-sm font-semibold text-primary">
                        {fmt(line.price)}
                        <span className="ml-1 text-xs font-normal text-muted-foreground">
                            / {suffix[line.price_type] ?? line.price_type}
                        </span>
                    </p>
                    <span className="text-xs text-muted-foreground">× {line.quantity}</span>
                </div>
            </div>
        </div>
    );
}

function MenuRow({
    item,
    fmt,
    onAdd,
}: {
    item: MenuItem;
    fmt: (n: number) => string;
    onAdd: (id: number) => void;
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
                    <img src={item.picture_url} alt={item.name} className="h-full w-full object-cover" />
                ) : (
                    <div className="flex h-full w-full items-center justify-center text-muted-foreground">
                        <ImageOff className="h-4 w-4 opacity-30" />
                    </div>
                )}
            </div>

            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{item.name}</p>
                {item.description && (
                    <p className="truncate text-xs text-muted-foreground">{item.description}</p>
                )}
                <p className="text-xs font-semibold text-primary">
                    {fmt(item.price)}
                    <span className="ml-1 font-normal text-muted-foreground">
                        / {suffix[item.price_type] ?? item.price_type}
                    </span>
                </p>
            </div>

            <button
                onClick={() => onAdd(item.id)}
                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground transition-opacity hover:opacity-90 active:scale-95"
            >
                <Plus className="h-4 w-4" />
            </button>
        </div>
    );
}
