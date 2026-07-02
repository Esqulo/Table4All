import { Head, Link } from '@inertiajs/react';
import { BarChart3, ShoppingBag, TrendingUp, UtensilsCrossed } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import DashboardController from '@/actions/App/Http/Controllers/Restaurant/DashboardController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { PaymentMethod } from '@/types';

type Summary = {
    total_revenue: number;
    tables_closed: number;
    items_sold: number;
    avg_table_revenue: number;
};

type RevenueDay = { date: string; revenue: number; tables_count: number };
type TopProduct = { name: string; qty: number; revenue: number };
type PaymentMethodData = { method: PaymentMethod; count: number; total: number };
type RecentTable = {
    id: number;
    title: string;
    closed_at: string;
    created_at: string;
    total: number;
    items: number;
};

type Props = {
    period: number;
    summary: Summary;
    revenue_by_day: RevenueDay[];
    top_products: TopProduct[];
    payment_methods: PaymentMethodData[];
    recent_tables: RecentTable[];
};

function formatCurrency(value: number) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
}

function formatDate(iso: string) {
    return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit' }).format(new Date(iso + 'T00:00:00'));
}

function formatDateTime(iso: string) {
    return new Intl.DateTimeFormat('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));
}

function MetricCard({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
    return (
        <div className="rounded-xl border p-4">
            <div className="mb-2 flex items-center gap-2 text-muted-foreground">
                {icon}
                <span className="text-sm">{label}</span>
            </div>
            <div className="text-2xl font-bold">{value}</div>
        </div>
    );
}

export default function Dashboards({
    period,
    summary,
    revenue_by_day,
    top_products,
    payment_methods,
    recent_tables,
}: Props) {
    const { t } = useTranslation();
    const maxRevenue = Math.max(...revenue_by_day.map((d) => d.revenue), 1);

    return (
        <>
            <Head title={t('dashboards.title')} />

            <div className="space-y-6 p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <Heading title={t('dashboards.title')} />
                    <div className="flex gap-2">
                        {([7, 30, 90] as const).map((p) => (
                            <Button
                                key={p}
                                size="sm"
                                variant={period === p ? 'default' : 'outline'}
                                asChild
                            >
                                <Link href={DashboardController.index.url({ query: { period: p } })}>
                                    {t(`dashboards.period_${p}`)}
                                </Link>
                            </Button>
                        ))}
                    </div>
                </div>

                {/* Summary cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard
                        icon={<TrendingUp className="h-5 w-5 text-primary" />}
                        label={t('dashboards.total_revenue')}
                        value={formatCurrency(summary.total_revenue)}
                    />
                    <MetricCard
                        icon={<UtensilsCrossed className="h-5 w-5 text-primary" />}
                        label={t('dashboards.tables_closed')}
                        value={String(summary.tables_closed)}
                    />
                    <MetricCard
                        icon={<ShoppingBag className="h-5 w-5 text-primary" />}
                        label={t('dashboards.items_sold')}
                        value={String(summary.items_sold)}
                    />
                    <MetricCard
                        icon={<BarChart3 className="h-5 w-5 text-primary" />}
                        label={t('dashboards.avg_table_revenue')}
                        value={formatCurrency(summary.avg_table_revenue)}
                    />
                </div>

                {/* Revenue bar chart */}
                {revenue_by_day.length > 0 && (
                    <div className="rounded-xl border p-4">
                        <h3 className="mb-4 text-sm font-semibold">{t('dashboards.revenue_by_day')}</h3>
                        <div className="flex h-32 items-end gap-1">
                            {revenue_by_day.map((day) => (
                                <div
                                    key={day.date}
                                    className="group relative flex flex-1 flex-col items-center gap-1"
                                    title={`${formatDate(day.date)}: ${formatCurrency(day.revenue)}`}
                                >
                                    <div
                                        className="w-full rounded-t-sm bg-primary/70 transition-colors hover:bg-primary"
                                        style={{ height: `${Math.max(4, (day.revenue / maxRevenue) * 100)}%` }}
                                    />
                                    {revenue_by_day.length <= 14 && (
                                        <span className="text-[10px] text-muted-foreground">
                                            {formatDate(day.date)}
                                        </span>
                                    )}
                                </div>
                            ))}
                        </div>
                        {revenue_by_day.length > 14 && (
                            <div className="mt-2 flex justify-between text-xs text-muted-foreground">
                                <span>{formatDate(revenue_by_day[0].date)}</span>
                                <span>{formatDate(revenue_by_day[revenue_by_day.length - 1].date)}</span>
                            </div>
                        )}
                    </div>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Top products */}
                    <div className="rounded-xl border p-4">
                        <h3 className="mb-4 text-sm font-semibold">{t('dashboards.top_products')}</h3>
                        {top_products.length === 0 ? (
                            <p className="text-sm text-muted-foreground">{t('dashboards.no_data')}</p>
                        ) : (
                            <div className="divide-y">
                                {top_products.map((product, i) => (
                                    <div key={product.name} className="flex items-center gap-3 py-2">
                                        <span className="w-4 text-sm font-medium text-muted-foreground">
                                            {i + 1}
                                        </span>
                                        <span className="flex-1 truncate text-sm">{product.name}</span>
                                        <span className="text-xs text-muted-foreground">{product.qty}×</span>
                                        <span className="text-sm font-semibold">
                                            {formatCurrency(product.revenue)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Payment methods */}
                    <div className="rounded-xl border p-4">
                        <h3 className="mb-4 text-sm font-semibold">{t('dashboards.payment_methods')}</h3>
                        {payment_methods.length === 0 ? (
                            <p className="text-sm text-muted-foreground">{t('dashboards.no_data')}</p>
                        ) : (
                            <div className="divide-y">
                                {payment_methods.map((pm) => (
                                    <div key={pm.method} className="flex items-center gap-3 py-2">
                                        <span className="flex-1 text-sm">
                                            {t(`tables.payment_methods.${pm.method}`)}
                                        </span>
                                        <Badge variant="outline" className="text-xs">
                                            {pm.count}×
                                        </Badge>
                                        <span className="text-sm font-semibold">
                                            {formatCurrency(pm.total)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                {/* Recent closed tables */}
                <div className="rounded-xl border p-4">
                    <h3 className="mb-4 text-sm font-semibold">{t('dashboards.recent_tables')}</h3>
                    {recent_tables.length === 0 ? (
                        <p className="text-sm text-muted-foreground">{t('dashboards.no_data')}</p>
                    ) : (
                        <div className="divide-y">
                            {recent_tables.map((table) => (
                                <div key={table.id} className="flex items-center gap-4 py-2">
                                    <div className="min-w-0 flex-1">
                                        <span className="text-sm font-medium">{table.title}</span>
                                        <div className="text-xs text-muted-foreground">
                                            {formatDateTime(table.closed_at)} · {table.items}{' '}
                                            {t('dashboards.items')}
                                        </div>
                                    </div>
                                    <span className="text-sm font-semibold">
                                        {formatCurrency(table.total)}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

Dashboards.layout = {
    breadcrumbs: [{ title: 'dashboards.breadcrumb_index', href: DashboardController.index.url() }],
};
