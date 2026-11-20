import { Head } from '@inertiajs/react';
import {
    BarChart3,
    Clock,
    Crown,
    Flame,
    Layers,
    PackageX,
    QrCode,
    ShoppingBag,
    TrendingUp,
    UtensilsCrossed,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import DashboardController from '@/actions/App/Http/Controllers/Restaurant/DashboardController';
import DashboardFiltersBar from '@/components/dashboard/dashboard-filters';
import {
    formatDateTime,
    formatDay,
    formatNumber,
    formatPercent,
    intlLocale,
    weekdayName,
} from '@/components/dashboard/format';
import KpiCard from '@/components/dashboard/kpi-card';
import PaymentShare from '@/components/dashboard/payment-share';
import PeakHoursHeatmap from '@/components/dashboard/peak-hours-heatmap';
import RankedBars from '@/components/dashboard/ranked-bars';
import TrendChart from '@/components/dashboard/trend-chart';
import Heading from '@/components/heading';
import { formatBRL } from '@/lib/money';
import { cn } from '@/lib/utils';
import type {
    CategoryRevenue,
    DashboardFilters,
    DashboardProduct,
    DashboardSummary,
    HeatmapCell,
    PaymentMethodTotal,
    RecentTable,
    TimelinePoint,
} from '@/types';

type Props = {
    filters: DashboardFilters;
    categories: { id: number; name: string }[];
    summary: DashboardSummary;
    previous_summary: DashboardSummary;
    timeline: TimelinePoint[];
    heatmap: HeatmapCell[];
    top_products: DashboardProduct[];
    top_products_by_qty: DashboardProduct[];
    revenue_by_category: CategoryRevenue[];
    payment_methods: PaymentMethodTotal[];
    recent_tables: RecentTable[];
};

function Panel({
    title,
    action,
    children,
    className,
}: {
    title: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section
            className={cn(
                'min-w-0 rounded-2xl border bg-card p-4 shadow-xs',
                className,
            )}
        >
            <div className="mb-4 flex min-h-10 items-center justify-between gap-3 border-b border-dashed pb-3">
                <h3 className="font-receipt text-[11px] font-medium tracking-[0.14em] text-muted-foreground uppercase">
                    {title}
                </h3>
                {action}
            </div>
            {children}
        </section>
    );
}

function Insight({
    icon,
    label,
    value,
    detail,
}: {
    icon: ReactNode;
    label: string;
    value: string;
    detail?: string;
}) {
    return (
        <div className="flex min-w-0 items-start gap-3 rounded-2xl border bg-card p-3.5 shadow-xs">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                {icon}
            </span>
            <div className="min-w-0">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className="truncate text-base font-bold tabular-nums">
                    {value}
                </p>
                {detail && (
                    <p className="truncate text-xs text-muted-foreground">
                        {detail}
                    </p>
                )}
            </div>
        </div>
    );
}

export default function Dashboards({
    filters,
    categories,
    summary,
    previous_summary: previous,
    timeline,
    heatmap,
    top_products,
    top_products_by_qty,
    revenue_by_category,
    payment_methods,
    recent_tables,
}: Props) {
    const { t, i18n } = useTranslation();
    const language = i18n.language;
    const locale = intlLocale(language);
    const money = (value: number) => formatBRL(value, locale);
    const [loading, setLoading] = useState(false);
    const [productsBy, setProductsBy] = useState<'revenue' | 'qty'>('revenue');
    const hourly = filters.granularity === 'hour';
    const products =
        productsBy === 'revenue' ? top_products : top_products_by_qty;
    const categoryName = categories.find(
        (c) => c.id === filters.category,
    )?.name;

    const rangeLabel =
        filters.from === filters.to
            ? formatDay(filters.from, language, 'long')
            : `${formatDay(filters.from, language)} – ${formatDay(filters.to, language)}`;
    const previousLabel =
        filters.previous_from === filters.previous_to
            ? formatDay(filters.previous_from, language, 'long')
            : `${formatDay(filters.previous_from, language)} – ${formatDay(filters.previous_to, language)}`;

    // Highlights, derived from what's already on the page.
    const best = timeline.reduce<TimelinePoint | null>(
        (top, point) =>
            point.revenue > 0 && (!top || point.revenue > top.revenue)
                ? point
                : top,
        null,
    );
    const weekdayItems = new Map<number, number>();

    for (const cell of heatmap) {
        weekdayItems.set(
            cell.weekday,
            (weekdayItems.get(cell.weekday) ?? 0) + cell.items,
        );
    }

    const busiestWeekday = [...weekdayItems.entries()].sort(
        (a, b) => b[1] - a[1],
    )[0];
    const qrShare =
        summary.total_revenue > 0
            ? summary.qr_revenue / summary.total_revenue
            : 0;

    return (
        <>
            <Head title={t('dashboards.title')} />

            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
                <div className="space-y-4">
                    <div className="flex flex-wrap items-end justify-between gap-2">
                        <div className="[&_header]:mb-2">
                            <Heading title={t('dashboards.title')} />
                            <p className="text-sm text-muted-foreground">
                                <span className="font-medium text-foreground">
                                    {rangeLabel}
                                </span>{' '}
                                ·{' '}
                                {t('dashboards.compared_with', {
                                    range: previousLabel,
                                })}
                                {categoryName && (
                                    <>
                                        {' '}
                                        ·{' '}
                                        {t('dashboards.only_category', {
                                            name: categoryName,
                                        })}
                                    </>
                                )}
                            </p>
                        </div>
                    </div>
                    <DashboardFiltersBar
                        key={`${filters.period}:${filters.from}:${filters.to}`}
                        filters={filters}
                        categories={categories}
                        onLoadingChange={setLoading}
                    />
                </div>

                <div
                    className={cn(
                        'space-y-6 transition-opacity',
                        loading && 'pointer-events-none opacity-60',
                    )}
                    aria-busy={loading}
                >
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <KpiCard
                            icon={
                                <TrendingUp className="size-5 text-primary" />
                            }
                            label={t('dashboards.total_revenue')}
                            value={money(summary.total_revenue)}
                            current={summary.total_revenue}
                            previous={previous.total_revenue}
                        />
                        <KpiCard
                            icon={
                                <UtensilsCrossed className="size-5 text-primary" />
                            }
                            label={t('dashboards.tables_closed')}
                            value={String(summary.tables_closed)}
                            current={summary.tables_closed}
                            previous={previous.tables_closed}
                        />
                        <KpiCard
                            icon={<BarChart3 className="size-5 text-primary" />}
                            label={t('dashboards.avg_table_revenue')}
                            value={money(summary.avg_table_revenue)}
                            current={summary.avg_table_revenue}
                            previous={previous.avg_table_revenue}
                        />
                        <KpiCard
                            icon={
                                <ShoppingBag className="size-5 text-primary" />
                            }
                            label={t('dashboards.items_sold')}
                            value={String(summary.items_sold)}
                            current={summary.items_sold}
                            previous={previous.items_sold}
                        />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <Insight
                            icon={<Clock className="size-[18px]" />}
                            label={t('dashboards.insights.avg_duration')}
                            value={
                                summary.tables_closed > 0
                                    ? t('dashboards.insights.minutes', {
                                          count: summary.avg_duration_minutes,
                                      })
                                    : '—'
                            }
                            detail={t('dashboards.insights.items_per_table', {
                                value: formatNumber(
                                    summary.avg_items_per_table,
                                    language,
                                ),
                            })}
                        />
                        <Insight
                            icon={<QrCode className="size-[18px]" />}
                            label={t('dashboards.insights.qr_share')}
                            value={formatPercent(qrShare, language)}
                            detail={t('dashboards.insights.qr_detail', {
                                value: money(summary.qr_revenue),
                            })}
                        />
                        <Insight
                            icon={<Crown className="size-[18px]" />}
                            label={
                                hourly
                                    ? t('dashboards.insights.best_hour')
                                    : t('dashboards.insights.best_day')
                            }
                            value={
                                best
                                    ? hourly
                                        ? `${best.bucket}h`
                                        : formatDay(
                                              best.bucket,
                                              language,
                                              'long',
                                          )
                                    : '—'
                            }
                            detail={best ? money(best.revenue) : undefined}
                        />
                        <Insight
                            icon={<Flame className="size-[18px]" />}
                            label={t('dashboards.insights.busiest_weekday')}
                            value={
                                busiestWeekday
                                    ? weekdayName(busiestWeekday[0], language)
                                    : '—'
                            }
                            detail={
                                busiestWeekday
                                    ? t('dashboards.items_count', {
                                          count: busiestWeekday[1],
                                      })
                                    : hourly
                                      ? t('dashboards.insights.needs_more_days')
                                      : undefined
                            }
                        />
                    </div>

                    {/* Orders the customer left without: prepared or owed, never billed */}
                    {summary.abandoned_items > 0 && (
                        <div className="flex items-start gap-3 rounded-2xl border border-destructive/30 bg-destructive/5 p-4">
                            <PackageX className="mt-0.5 size-5 shrink-0 text-destructive" />
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-semibold">
                                    {t('dashboards.abandoned_title')}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {t('dashboards.abandoned_summary', {
                                        count: summary.abandoned_items,
                                        value: money(summary.abandoned_value),
                                    })}
                                </p>
                            </div>
                        </div>
                    )}

                    <TrendChart timeline={timeline} filters={filters} />

                    <div className="grid gap-4 lg:grid-cols-2">
                        <Panel
                            title={t('dashboards.top_products')}
                            action={
                                <div
                                    role="group"
                                    aria-label={t('dashboards.rank_by')}
                                    className="inline-flex rounded-full bg-muted p-0.5"
                                >
                                    {(['revenue', 'qty'] as const).map((by) => (
                                        <button
                                            key={by}
                                            type="button"
                                            aria-pressed={productsBy === by}
                                            onClick={() => setProductsBy(by)}
                                            className={cn(
                                                'rounded-full px-2.5 py-0.5 text-xs font-medium transition-colors',
                                                productsBy === by
                                                    ? 'bg-card text-foreground shadow-xs'
                                                    : 'text-muted-foreground hover:text-foreground',
                                            )}
                                        >
                                            {t(`dashboards.rank_by_${by}`)}
                                        </button>
                                    ))}
                                </div>
                            }
                        >
                            {products.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('dashboards.no_data')}
                                </p>
                            ) : (
                                <RankedBars
                                    rows={products.map((p) => ({
                                        key: p.id,
                                        label: p.name,
                                        value:
                                            productsBy === 'revenue'
                                                ? p.revenue
                                                : p.qty,
                                        display:
                                            productsBy === 'revenue'
                                                ? money(p.revenue)
                                                : `${p.qty}×`,
                                        detail:
                                            productsBy === 'revenue'
                                                ? `${p.qty}×`
                                                : money(p.revenue),
                                    }))}
                                />
                            )}
                        </Panel>

                        {filters.category === null ? (
                            <Panel
                                title={t('dashboards.revenue_by_category')}
                                action={
                                    <Layers className="size-4 text-muted-foreground" />
                                }
                            >
                                {revenue_by_category.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        {t('dashboards.no_data')}
                                    </p>
                                ) : (
                                    <RankedBars
                                        rows={revenue_by_category.map((c) => ({
                                            key: c.id ?? 'none',
                                            label:
                                                c.name ??
                                                t('dashboards.uncategorized'),
                                            value: c.revenue,
                                            display: money(c.revenue),
                                            detail: formatPercent(
                                                summary.total_revenue > 0
                                                    ? c.revenue /
                                                          summary.total_revenue
                                                    : 0,
                                                language,
                                            ),
                                        }))}
                                    />
                                )}
                            </Panel>
                        ) : (
                            <Panel title={t('dashboards.payment_methods')}>
                                <PaymentShare methods={payment_methods} />
                                <p className="mt-3 text-xs text-muted-foreground">
                                    {t('dashboards.payments_whole_bill')}
                                </p>
                            </Panel>
                        )}
                    </div>

                    <div className="grid items-start gap-4 lg:grid-cols-5">
                        {!hourly && (
                            <Panel
                                title={t('dashboards.heatmap.title')}
                                className="lg:col-span-3"
                            >
                                <PeakHoursHeatmap cells={heatmap} />
                            </Panel>
                        )}
                        {filters.category === null && (
                            <Panel
                                title={t('dashboards.payment_methods')}
                                className={
                                    hourly ? 'lg:col-span-5' : 'lg:col-span-2'
                                }
                            >
                                <PaymentShare methods={payment_methods} />
                            </Panel>
                        )}
                    </div>

                    <Panel title={t('dashboards.recent_tables')}>
                        {recent_tables.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('dashboards.no_data')}
                            </p>
                        ) : (
                            <div className="divide-y">
                                {recent_tables.map((table) => (
                                    <div
                                        key={table.id}
                                        className="flex items-center gap-4 py-2"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <span className="text-sm font-medium">
                                                {table.title}
                                            </span>
                                            <div className="text-xs text-muted-foreground">
                                                {formatDateTime(
                                                    table.closed_at,
                                                    language,
                                                )}{' '}
                                                ·{' '}
                                                {t('dashboards.items_count', {
                                                    count: table.items,
                                                })}
                                            </div>
                                        </div>
                                        <span className="text-sm font-semibold tabular-nums">
                                            {money(table.total)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Panel>
                </div>
            </div>
        </>
    );
}

Dashboards.layout = {
    breadcrumbs: [
        {
            title: 'dashboards.breadcrumb_index',
            href: DashboardController.index.url(),
        },
    ],
};
