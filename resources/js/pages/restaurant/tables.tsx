import { Form, Head, Link } from '@inertiajs/react';
import { ImageOff, Pencil, ShoppingBag, Trash2 } from 'lucide-react';
import { Plus } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import TableController from '@/actions/App/Http/Controllers/Restaurant/TableController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import type { RestaurantTable } from '@/types';

type Props = {
    tables: RestaurantTable[];
};

export default function Tables({ tables }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('tables.title')} />

            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading title={t('tables.title')} />
                    <Button asChild>
                        <Link href={TableController.create.url()}>
                            <Plus className="mr-2 h-4 w-4" />
                            {t('tables.new')}
                        </Link>
                    </Button>
                </div>

                {tables.length === 0 ? (
                    <p className="py-12 text-center text-sm text-muted-foreground">
                        {t('tables.empty')}
                    </p>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {tables.map((table) => (
                            <TableCard key={table.id} table={table} />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

function TableCard({ table }: { table: RestaurantTable }) {
    const { t } = useTranslation();

    const total = table.products.reduce((sum, p) => sum + p.price * p.pivot.quantity, 0);
    const totalFormatted = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(total);

    return (
        <div className="flex flex-col overflow-hidden rounded-xl border border-border bg-card shadow-xs">
            <div className="flex items-start justify-between gap-2 p-4">
                <div>
                    <p className="text-lg font-semibold leading-tight">{table.title}</p>
                    <p className="text-xs text-muted-foreground">
                        {t('tables.products_count', { count: table.products_count })}
                    </p>
                </div>
                {total > 0 && (
                    <span className="shrink-0 rounded-full bg-primary/10 px-2.5 py-1 text-sm font-semibold text-primary">
                        {totalFormatted}
                    </span>
                )}
            </div>

            {table.products.length > 0 && (
                <div className="flex flex-wrap gap-2 border-t border-border px-4 py-3">
                    {table.products.slice(0, 5).map((product) => (
                        <div
                            key={product.id}
                            title={product.name}
                            className="relative h-9 w-9 overflow-hidden rounded-md bg-muted"
                        >
                            {product.picture_url ? (
                                <img
                                    src={product.picture_url}
                                    alt={product.name}
                                    className="h-full w-full object-cover"
                                />
                            ) : (
                                <div className="flex h-full w-full items-center justify-center text-muted-foreground">
                                    <ImageOff className="h-3.5 w-3.5 opacity-40" />
                                </div>
                            )}
                        </div>
                    ))}
                    {table.products_count > 5 && (
                        <div className="flex h-9 w-9 items-center justify-center rounded-md bg-muted text-xs text-muted-foreground">
                            +{table.products_count - 5}
                        </div>
                    )}
                </div>
            )}

            <div className="mt-auto flex flex-col gap-2 border-t border-border p-3">
                <Button asChild className="w-full">
                    <Link href={TableController.show.url({ table: table.id })}>
                        <ShoppingBag className="mr-2 h-4 w-4" />
                        {t('tables.manage_products')}
                    </Link>
                </Button>

                <div className="flex gap-2">
                    <Button variant="outline" size="sm" asChild className="flex-1">
                        <Link href={TableController.edit.url({ table: table.id })}>
                            <Pencil className="mr-1 h-3.5 w-3.5" />
                            {t('tables.edit')}
                        </Link>
                    </Button>

                    <Form
                        {...TableController.destroy.form({ table: table.id })}
                        className="flex-1"
                        onBefore={() => confirm(t('tables.delete_confirm'))}
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
                                {t('tables.delete')}
                            </Button>
                        )}
                    </Form>
                </div>
            </div>
        </div>
    );
}

Tables.layout = {
    breadcrumbs: [
        {
            title: 'tables.breadcrumb_index',
            href: TableController.index.url(),
        },
    ],
};
