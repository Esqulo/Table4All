import { Head } from '@inertiajs/react';
import { ImageOff } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { Category, Product } from '@/types';

type Restaurant = { name: string; avatar_url: string | null };
type TableInfo = { id: number; title: string };

type Props = {
    table: TableInfo;
    restaurant: Restaurant;
    categories: Category[];
    products: Product[];
};

const ALL_ID = 0;
const OTHER_ID = -1;

export default function TableShow({ table, restaurant, categories, products }: Props) {
    const { t } = useTranslation();
    const [activeCategory, setActiveCategory] = useState<number>(ALL_ID);

    const hasOther = products.some((p) => p.category_id === null);

    const tabs = [
        { id: ALL_ID, name: t('join_table.all_categories') },
        ...categories,
        ...(hasOther ? [{ id: OTHER_ID, name: t('join_table.other_category') }] : []),
    ];

    const filtered =
        activeCategory === ALL_ID
            ? products
            : activeCategory === OTHER_ID
              ? products.filter((p) => p.category_id === null)
              : products.filter((p) => p.category_id === activeCategory);

    return (
        <>
            <Head title={`${restaurant.name} — ${table.title}`} />

            <div className="bg-background">
                {/* Header */}
                <div className="border-b border-border bg-card px-4 pb-4 pt-8">
                    <div className="mx-auto flex max-w-2xl items-center gap-3">
                        {restaurant.avatar_url ? (
                            <img
                                src={restaurant.avatar_url}
                                alt={restaurant.name}
                                className="h-12 w-12 shrink-0 rounded-full object-cover"
                            />
                        ) : (
                            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-muted">
                                <span className="text-lg font-bold text-muted-foreground">
                                    {restaurant.name[0].toUpperCase()}
                                </span>
                            </div>
                        )}
                        <div className="min-w-0">
                            <p className="text-xs text-muted-foreground">{restaurant.name}</p>
                            <h1 className="truncate text-lg font-bold leading-tight">
                                <span className="font-normal text-muted-foreground">
                                    {t('join_table.table_at')}{' '}
                                </span>
                                {table.title}
                            </h1>
                        </div>
                    </div>
                </div>

                {/* Category tabs */}
                {tabs.length > 1 && (
                    <div className="sticky top-12 z-10 border-b border-border bg-background lg:top-0">
                        <div className="mx-auto max-w-2xl">
                            <div className="flex gap-1 overflow-x-auto px-4 py-2 scrollbar-none">
                                {tabs.map((tab) => (
                                    <button
                                        key={tab.id}
                                        onClick={() => setActiveCategory(tab.id)}
                                        className={[
                                            'shrink-0 rounded-full px-4 py-1.5 text-sm font-medium transition-colors',
                                            activeCategory === tab.id
                                                ? 'bg-primary text-primary-foreground'
                                                : 'bg-muted text-muted-foreground hover:bg-muted/80',
                                        ].join(' ')}
                                    >
                                        {tab.name}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                )}

                {/* Products */}
                <div className="mx-auto max-w-2xl space-y-3 px-4 py-6">
                    {filtered.length === 0 ? (
                        <p className="py-16 text-center text-sm text-muted-foreground">
                            {t('join_table.no_products')}
                        </p>
                    ) : (
                        filtered.map((product) => (
                            <ProductCard key={product.id} product={product} />
                        ))
                    )}
                </div>
            </div>
        </>
    );
}

function ProductCard({ product }: { product: Product }) {
    const { i18n } = useTranslation();
    const locale = i18n.language === 'pt_BR' ? 'pt-BR' : 'en-US';

    const priceFormatted = new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: 'BRL',
    }).format(product.price);

    const suffix: Record<string, string> = {
        unit: 'un.',
        kg: 'kg',
        '100g': '100g',
        liter: 'L',
        portion: 'porção',
    };

    return (
        <div className="flex gap-3 rounded-xl border border-border bg-card p-3">
            <div className="h-20 w-20 shrink-0 overflow-hidden rounded-lg bg-muted">
                {product.picture_url ? (
                    <img
                        src={product.picture_url}
                        alt={product.name}
                        className="h-full w-full object-cover"
                    />
                ) : (
                    <div className="flex h-full w-full items-center justify-center text-muted-foreground">
                        <ImageOff className="h-6 w-6 opacity-30" />
                    </div>
                )}
            </div>

            <div className="flex min-w-0 flex-1 flex-col justify-between">
                <div>
                    <p className="font-medium leading-snug">{product.name}</p>
                    {product.description && (
                        <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                            {product.description}
                        </p>
                    )}
                </div>
                <p className="text-sm font-semibold text-primary">
                    {priceFormatted}
                    <span className="ml-1 text-xs font-normal text-muted-foreground">
                        / {suffix[product.price_type] ?? product.price_type}
                    </span>
                </p>
            </div>
        </div>
    );
}
