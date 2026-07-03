import { Head } from '@inertiajs/react';
import { ImageOff } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import JoinController from '@/actions/App/Http/Controllers/Customer/JoinController';
import type { Product } from '@/types';

type Props = {
    products: Product[];
};

export default function CustomerShow({ products }: Props) {
    const { t } = useTranslation();


    return (
        <>
            <Head title={t('join_table.title')} />

            {/* Products */}
            <div className="mx-auto max-w-2xl space-y-3 px-4 py-6">
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
        </>
    );
}

CustomerShow.layout = {
    breadcrumbs: [{ title: 'join_table.title', href: JoinController.index.url() }],
};

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
