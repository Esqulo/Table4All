import { Head } from '@inertiajs/react';
import { ImageOff, Minus, Plus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import JoinController from '@/actions/App/Http/Controllers/Customer/JoinController';

type OrderLine = {
    id: number;
    name: string;
    description: string | null;
    price: number;
    price_type: string;
    quantity: number;
    picture_url: string | null;
};

type Props = {
    products: OrderLine[];
    total: number;
    paid: number;
    remaining: number;
};

export default function CustomerShow({ products, total, paid, remaining }: Props) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language === 'pt_BR' ? 'pt-BR' : 'en-US';
    const [people, setPeople] = useState(1);

    const fmt = (n: number) =>
        new Intl.NumberFormat(locale, { style: 'currency', currency: 'BRL' }).format(n);

    const perPerson = people > 0 ? remaining / people : 0;

    return (
        <>
            <Head title={t('join_table.title')} />

            <div className="mx-auto max-w-5xl px-4 py-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start">

                    {/* Left — products */}
                    <div className="flex-1 space-y-3">
                        <p className="text-sm font-semibold">{t('join_table.products_title')}</p>
                        {products.length === 0 ? (
                            <p className="py-16 text-center text-sm text-muted-foreground">
                                {t('join_table.no_products')}
                            </p>
                        ) : (
                            products.map((product) => (
                                <ProductCard key={product.id} product={product} />
                            ))
                        )}
                    </div>

                    {/* Right — summary + splitter */}
                    <div className="flex w-full flex-col gap-3 lg:w-72 lg:shrink-0">
                        <p className="text-sm font-semibold">{t('join_table.summary_title')}</p>

                        {/* Summary */}
                        <div className="rounded-xl border border-border bg-card p-4 space-y-3">
                            <div className="space-y-1.5 text-sm">
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

                        {/* Bill splitter */}
                        {remaining > 0 && (
                            <div className="rounded-xl border border-border bg-card p-4 space-y-3">
                                <p className="text-sm font-semibold">{t('join_table.split_title')}</p>
                                <div className="flex items-center justify-between">
                                    <span className="text-sm text-muted-foreground">{t('join_table.people')}</span>
                                    <div className="flex items-center gap-3">
                                        <button
                                            onClick={() => setPeople((n) => Math.max(1, n - 1))}
                                            className="flex h-8 w-8 items-center justify-center rounded-full border border-border bg-muted text-muted-foreground transition-colors hover:bg-muted/80 disabled:opacity-40"
                                            disabled={people <= 1}
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
        </>
    );
}

CustomerShow.layout = {
    breadcrumbs: [{ title: 'join_table.title', href: JoinController.index.url() }],
};

function ProductCard({ product }: { product: OrderLine }) {
    const { i18n } = useTranslation();
    const locale = i18n.language === 'pt_BR' ? 'pt-BR' : 'en-US';

    const fmt = (n: number) =>
        new Intl.NumberFormat(locale, { style: 'currency', currency: 'BRL' }).format(n);

    return (
        <div className="flex gap-3 rounded-xl border border-border bg-card p-3">
            <div className="h-16 w-16 shrink-0 overflow-hidden rounded-lg bg-muted">
                {product.picture_url ? (
                    <img
                        src={product.picture_url}
                        alt={product.name}
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
                    <p className="font-medium leading-snug">{product.name}</p>
                    {product.description && (
                        <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                            {product.description}
                        </p>
                    )}
                </div>
                <div className="flex items-baseline justify-between">
                    <p className="text-sm font-semibold text-primary">{fmt(product.price)}</p>
                    <p className="text-xs text-muted-foreground">× {product.quantity}</p>
                </div>
            </div>
        </div>
    );
}
