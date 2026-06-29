import { Form, Head, Link } from '@inertiajs/react';
import { ImageOff, Pencil, Plus, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import ProductController from '@/actions/App/Http/Controllers/Restaurant/ProductController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import type { Product } from '@/types';

type Props = {
    products: Product[];
};

export default function Products({ products }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('products.title')} />

            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading title={t('products.title')} />
                    <Button asChild>
                        <Link href={ProductController.create.url()}>
                            <Plus className="mr-2 h-4 w-4" />
                            {t('products.new')}
                        </Link>
                    </Button>
                </div>

                {products.length === 0 ? (
                    <p className="text-center text-sm text-muted-foreground py-12">
                        {t('products.empty')}
                    </p>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {products.map((product) => (
                            <ProductCard key={product.id} product={product} />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

function ProductCard({ product }: { product: Product }) {
    const { t } = useTranslation();

    const priceFormatted = new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(product.price);

    const suffix = t(`products.price_suffix.${product.price_type}`);

    return (
        <div className="flex flex-col overflow-hidden rounded-xl border border-border bg-card shadow-xs">
            <div className="relative aspect-video overflow-hidden bg-muted">
                {product.picture_url ? (
                    <img
                        src={product.picture_url}
                        alt={product.name}
                        className="h-full w-full object-cover"
                    />
                ) : (
                    <div className="flex h-full w-full items-center justify-center text-muted-foreground">
                        <ImageOff className="h-10 w-10 opacity-30" />
                    </div>
                )}
            </div>

            <div className="flex flex-1 flex-col gap-1 p-4">
                <p className="font-medium leading-tight">{product.name}</p>
                {product.description && (
                    <p className="text-xs text-muted-foreground line-clamp-2">
                        {product.description}
                    </p>
                )}
                <p className="mt-1 text-sm font-semibold text-primary">
                    {priceFormatted}
                    <span className="ml-1 text-xs font-normal text-muted-foreground">
                        / {suffix}
                    </span>
                </p>
            </div>

            <div className="flex gap-2 border-t border-border px-4 py-3">
                <Button variant="outline" size="sm" asChild className="flex-1">
                    <Link href={ProductController.edit.url({ product: product.id })}>
                        <Pencil className="mr-1 h-3.5 w-3.5" />
                        {t('products.edit')}
                    </Link>
                </Button>

                <Form
                    {...ProductController.destroy.form({ product: product.id })}
                    className="flex-1"
                    onBefore={() => confirm(t('products.delete_confirm'))}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            disabled={processing}
                            className="w-full text-destructive hover:text-destructive"
                        >
                            <Trash2 className="mr-1 h-3.5 w-3.5" />
                            {t('products.delete')}
                        </Button>
                    )}
                </Form>
            </div>
        </div>
    );
}

Products.layout = {
    breadcrumbs: [
        {
            title: 'products.breadcrumb_index',
            href: ProductController.index.url(),
        },
    ],
};
