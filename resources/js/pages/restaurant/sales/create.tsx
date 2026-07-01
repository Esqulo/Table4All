import { Form, Head, Link } from '@inertiajs/react';
import { Check, ChevronDown, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import SaleController from '@/actions/App/Http/Controllers/Restaurant/SaleController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Product } from '@/types';

type ProductOption = Pick<Product, 'id' | 'name' | 'price' | 'price_type'>;

type Props = {
    products: ProductOption[];
};

function toDatetimeLocal(iso?: string): string {
    if (!iso) return '';
    return iso.slice(0, 16);
}

// ─── Searchable product combobox ──────────────────────────────────────────────

function ProductCombobox({
    products,
    error,
}: {
    products: ProductOption[];
    error?: string;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState<ProductOption | null>(null);
    const containerRef = useRef<HTMLDivElement>(null);
    const searchRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (open) searchRef.current?.focus();
    }, [open]);

    useEffect(() => {
        const handler = (e: MouseEvent) => {
            if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    const filtered = products.filter((p) =>
        p.name.toLowerCase().includes(search.toLowerCase()),
    );

    const handleSelect = (product: ProductOption) => {
        setSelected(product);
        setOpen(false);
        setSearch('');
    };

    return (
        <div ref={containerRef} className="relative">
            <input type="hidden" name="product_id" value={selected?.id ?? ''} />

            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                className={[
                    'flex h-9 w-full items-center justify-between rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs transition-colors',
                    'focus:outline-none focus:ring-1 focus:ring-ring',
                    error ? 'border-destructive' : 'border-input',
                    selected ? 'text-foreground' : 'text-muted-foreground',
                ].join(' ')}
            >
                <span className="truncate">
                    {selected ? selected.name : t('sales.product_placeholder')}
                </span>
                <ChevronDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
            </button>

            {open && (
                <div className="absolute z-50 mt-1 w-full rounded-md border border-border bg-popover shadow-md">
                    <div className="p-2">
                        <div className="relative">
                            <Search className="absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                ref={searchRef}
                                className="pl-8 h-8 text-sm"
                                placeholder={t('sales.product_search_placeholder')}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="max-h-52 overflow-y-auto border-t border-border">
                        {filtered.length === 0 ? (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                {t('sales.product_placeholder')}
                            </p>
                        ) : (
                            filtered.map((product) => (
                                <button
                                    key={product.id}
                                    type="button"
                                    onClick={() => handleSelect(product)}
                                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-accent hover:text-accent-foreground"
                                >
                                    <Check
                                        className={[
                                            'h-3.5 w-3.5 shrink-0',
                                            selected?.id === product.id ? 'opacity-100' : 'opacity-0',
                                        ].join(' ')}
                                    />
                                    <span className="truncate">{product.name}</span>
                                </button>
                            ))
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function CreateSale({ products }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('sales.breadcrumb_create')} />

            <div className="mx-auto max-w-xl space-y-6 p-6">
                <h1 className="text-xl font-semibold">{t('sales.breadcrumb_create')}</h1>

                <Form
                    {...SaleController.store.form()}
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="product_id">{t('sales.product_label')}</Label>
                                <ProductCombobox products={products} error={errors.product_id} />
                                <InputError message={errors.product_id} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="sale_price">{t('sales.sale_price_label')}</Label>
                                <Input
                                    id="sale_price"
                                    name="sale_price"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    required
                                    placeholder="0,00"
                                />
                                <InputError message={errors.sale_price} />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="starts_at">{t('sales.starts_at_label')}</Label>
                                    <Input
                                        id="starts_at"
                                        name="starts_at"
                                        type="datetime-local"
                                        required
                                        defaultValue={toDatetimeLocal(new Date().toISOString())}
                                    />
                                    <InputError message={errors.starts_at} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="ends_at">{t('sales.ends_at_label')}</Label>
                                    <Input
                                        id="ends_at"
                                        name="ends_at"
                                        type="datetime-local"
                                        required
                                    />
                                    <InputError message={errors.ends_at} />
                                </div>
                            </div>

                            <div className="flex gap-3 pt-2">
                                <Button type="submit" disabled={processing}>
                                    {t('sales.submit_create')}
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={SaleController.index.url()}>
                                        {t('sales.cancel')}
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

CreateSale.layout = {
    breadcrumbs: [
        {
            title: 'sales.breadcrumb_index',
            href: SaleController.index.url(),
        },
        {
            title: 'sales.breadcrumb_create',
            href: SaleController.create.url(),
        },
    ],
};
