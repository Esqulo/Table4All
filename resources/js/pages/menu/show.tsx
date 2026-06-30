import { Head } from '@inertiajs/react';
import { ImageOff } from 'lucide-react';
import { useState } from 'react';
import type { Category, Menu, Product } from '@/types';

type Restaurant = {
    id: number;
    name: string;
    avatar_url: string | null;
};

type Props = {
    menu: Menu & { user: Restaurant };
    categories: Category[];
    products: Product[];
};

const ALL_ID = 0;

export default function MenuShow({ menu, categories, products }: Props) {
    const [activeCategory, setActiveCategory] = useState<number>(ALL_ID);

    const tabs = [
        { id: ALL_ID, name: 'Todos' },
        ...categories,
        ...(products.some((p) => p.category_id === null) ? [{ id: -1, name: 'Outros' }] : []),
    ];

    const filtered = activeCategory === ALL_ID
        ? products
        : activeCategory === -1
          ? products.filter((p) => p.category_id === null)
          : products.filter((p) => p.category_id === activeCategory);

    return (
        <>
            <Head title={`${menu.user.name} — ${menu.name}`} />

            <div className="min-h-screen bg-background">
                {/* Header */}
                <div className="bg-card border-b border-border px-4 pt-8 pb-4">
                    <div className="mx-auto max-w-2xl flex items-center gap-3">
                        {menu.user.avatar_url ? (
                            <img
                                src={menu.user.avatar_url}
                                alt={menu.user.name}
                                className="h-12 w-12 rounded-full object-cover shrink-0"
                            />
                        ) : (
                            <div className="h-12 w-12 rounded-full bg-muted flex items-center justify-center shrink-0">
                                <span className="text-lg font-bold text-muted-foreground">
                                    {menu.user.name[0].toUpperCase()}
                                </span>
                            </div>
                        )}
                        <div>
                            <p className="text-xs text-muted-foreground">{menu.user.name}</p>
                            <h1 className="text-lg font-bold leading-tight">{menu.name}</h1>
                        </div>
                    </div>
                </div>

                {/* Category tabs */}
                {tabs.length > 1 && (
                    <div className="sticky top-0 z-10 bg-background border-b border-border">
                        <div className="mx-auto max-w-2xl">
                            <div className="flex overflow-x-auto scrollbar-none gap-1 px-4 py-2">
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
                <div className="mx-auto max-w-2xl px-4 py-6 space-y-3">
                    {filtered.length === 0 ? (
                        <p className="text-center text-sm text-muted-foreground py-16">
                            Nenhum produto disponível.
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
    const priceFormatted = new Intl.NumberFormat('pt-BR', {
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

            <div className="flex flex-1 flex-col justify-between min-w-0">
                <div>
                    <p className="font-medium leading-snug">{product.name}</p>
                    {product.description && (
                        <p className="mt-0.5 text-xs text-muted-foreground line-clamp-2">
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
