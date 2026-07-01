import { Form, Head, Link } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import SaleController from '@/actions/App/Http/Controllers/Restaurant/SaleController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { Sale } from '@/types';

type Props = {
    sales: Sale[];
};

type SaleStatus = 'active' | 'scheduled' | 'expired';

function getSaleStatus(sale: Sale): SaleStatus {
    const now = new Date();
    const start = new Date(sale.starts_at);
    const end = new Date(sale.ends_at);
    if (now < start) return 'scheduled';
    if (now > end) return 'expired';
    return 'active';
}

function formatCurrency(value: number) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
}

function formatDateRange(startsAt: string, endsAt: string) {
    const fmt = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
    return `${fmt.format(new Date(startsAt))} → ${fmt.format(new Date(endsAt))}`;
}

export default function Sales({ sales }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('sales.title')} />

            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading title={t('sales.title')} />
                    <Button asChild>
                        <Link href={SaleController.create.url()}>
                            <Plus className="mr-2 h-4 w-4" />
                            {t('sales.new')}
                        </Link>
                    </Button>
                </div>

                {sales.length === 0 ? (
                    <p className="text-center text-sm text-muted-foreground py-12">
                        {t('sales.empty')}
                    </p>
                ) : (
                    <div className="divide-y divide-border rounded-xl border border-border">
                        {sales.map((sale) => (
                            <SaleRow key={sale.id} sale={sale} />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

function SaleRow({ sale }: { sale: Sale }) {
    const { t } = useTranslation();
    const status = getSaleStatus(sale);

    const discount = sale.product.price > 0
        ? Math.round((1 - sale.sale_price / sale.product.price) * 100)
        : 0;

    const statusVariant: Record<SaleStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
        active: 'default',
        scheduled: 'secondary',
        expired: 'outline',
    };

    return (
        <div className="flex items-center gap-4 px-4 py-3">
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                    <span className="font-medium truncate">{sale.product.name}</span>
                    <Badge variant={statusVariant[status]}>
                        {t(`sales.status_${status}`)}
                    </Badge>
                    {discount > 0 && (
                        <Badge variant="secondary" className="text-xs">
                            -{discount}%
                        </Badge>
                    )}
                </div>

                <div className="mt-0.5 flex items-center gap-3 text-sm text-muted-foreground flex-wrap">
                    <span>
                        <span className="line-through">{formatCurrency(sale.product.price)}</span>
                        {' '}
                        <span className="font-semibold text-primary">{formatCurrency(sale.sale_price)}</span>
                    </span>
                    <span className="hidden sm:inline">·</span>
                    <span>{formatDateRange(sale.starts_at, sale.ends_at)}</span>
                </div>
            </div>

            <Form
                {...SaleController.destroy.form({ sale: sale.id })}
                onBefore={() => confirm(t('sales.delete_confirm'))}
            >
                {({ processing }) => (
                    <Button
                        type="submit"
                        variant="ghost"
                        size="icon"
                        disabled={processing}
                        className="text-destructive hover:text-destructive shrink-0"
                    >
                        <Trash2 className="h-4 w-4" />
                    </Button>
                )}
            </Form>
        </div>
    );
}

Sales.layout = {
    breadcrumbs: [
        {
            title: 'sales.breadcrumb_index',
            href: SaleController.index.url(),
        },
    ],
};
