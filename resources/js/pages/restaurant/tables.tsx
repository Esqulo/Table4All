import { Head, Link } from '@inertiajs/react';
import { ImageOff, Pencil, Plus, QrCode, ShoppingBag } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import TableController from '@/actions/App/Http/Controllers/Restaurant/TableController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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

const fmt = (n: number) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(n);

function TableQrDialog({ table, open, onClose }: { table: RestaurantTable; open: boolean; onClose: () => void }) {
    const { t } = useTranslation();
    const url = `${window.location.origin}/mesa/${table.access_code}`;

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-w-xs text-center">
                <DialogHeader className="items-center">
                    <DialogTitle>{t('join_table.qr_dialog_title')}</DialogTitle>
                    <DialogDescription>{t('join_table.qr_dialog_description')}</DialogDescription>
                </DialogHeader>
                <div className="flex flex-col items-center gap-4 py-2">
                    <div className="rounded-xl border border-border bg-white p-3">
                        <QRCodeSVG value={url} size={180} />
                    </div>
                    <div className="text-center">
                        <p className="text-xs text-muted-foreground">{t('join_table.access_code_label')}</p>
                        <p className="font-mono text-3xl font-bold tracking-widest">{table.access_code}</p>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function TableCard({ table }: { table: RestaurantTable }) {
    const { t } = useTranslation();
    const [qrOpen, setQrOpen] = useState(false);

    const total = table.products.reduce((sum, p) => sum + p.price * p.pivot.quantity, 0);
    const paid = table.payments.reduce((sum, p) => sum + p.amount, 0);
    const remaining = total - paid;

    return (
        <>
            {table.access_code && (
                <TableQrDialog table={table} open={qrOpen} onClose={() => setQrOpen(false)} />
            )}
        <div className="flex flex-col overflow-hidden rounded-xl border border-border bg-card shadow-xs">
            <div className="flex items-start justify-between gap-2 p-4">
                <div>
                    <p className="text-lg font-semibold leading-tight">{table.title}</p>
                    <p className="text-xs text-muted-foreground">
                        {t('tables.products_count', { count: table.products_count })}
                    </p>
                </div>
                {table.access_code && (
                    <button
                        onClick={() => setQrOpen(true)}
                        className="flex shrink-0 flex-col items-center gap-0.5 rounded-lg border border-border px-2 py-1 text-muted-foreground transition-colors hover:bg-muted"
                        title={t('join_table.qr_button')}
                    >
                        <QrCode className="h-4 w-4" />
                        <span className="font-mono text-[10px] leading-none tracking-widest">
                            {table.access_code}
                        </span>
                    </button>
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

            {total > 0 && (
                <div className="space-y-1 border-t border-border px-4 py-3 text-sm">
                    <div className="flex justify-between">
                        <span className="text-muted-foreground">{t('tables.order_total')}</span>
                        <span className="font-semibold tabular-nums">{fmt(total)}</span>
                    </div>
                    {paid > 0 && (
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">{t('tables.payment_total_paid')}</span>
                            <span className="font-semibold tabular-nums text-green-600">{fmt(paid)}</span>
                        </div>
                    )}
                    {remaining > 0 && (
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">{t('tables.payment_remaining')}</span>
                            <span className="font-semibold tabular-nums text-destructive">{fmt(remaining)}</span>
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

                <Button variant="outline" size="sm" asChild className="w-full">
                    <Link href={TableController.edit.url({ table: table.id })}>
                        <Pencil className="mr-1 h-3.5 w-3.5" />
                        {t('tables.edit')}
                    </Link>
                </Button>
            </div>
        </div>
        </>
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
