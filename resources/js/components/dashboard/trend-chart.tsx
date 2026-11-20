import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    Line,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { formatBRL } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { DashboardFilters, TimelinePoint } from '@/types';
import {
    addDays,
    bucketLabel,
    formatCompactBRL,
    formatDay,
    intlLocale,
} from './format';

type Metric = 'revenue' | 'tables';

const AXIS_TICK = { fontSize: 11, fill: 'var(--muted-foreground)' };

/**
 * Revenue (or tables closed) over the period, with the previous period as a
 * quiet reference line. Two measures never share an axis: the toggle swaps
 * the chart instead.
 */
export default function TrendChart({
    timeline,
    filters,
}: {
    timeline: TimelinePoint[];
    filters: DashboardFilters;
}) {
    const { t, i18n } = useTranslation();
    const [metric, setMetric] = useState<Metric>('revenue');
    const gradientId = useId().replace(/:/g, '');
    const language = i18n.language;
    const hourly = filters.granularity === 'hour';

    const data = timeline.map((point, index) => ({
        ...point,
        label: bucketLabel(point.bucket, filters.granularity, language),
        previousLabel: hourly
            ? `${formatDay(filters.previous_from, language)} · ${point.bucket}h`
            : formatDay(addDays(filters.previous_from, index), language),
    }));
    type Row = (typeof data)[number];

    // Fewer ticks on long ranges keeps labels from colliding on phones.
    const interval = hourly ? 2 : Math.max(0, Math.ceil(data.length / 8) - 1);

    const tooltip = ({
        active,
        payload,
    }: {
        active?: boolean;
        payload?: ReadonlyArray<{ payload?: Row }>;
    }) => {
        const row = payload?.[0]?.payload;

        if (!active || !row) {
            return null;
        }

        return (
            <div className="min-w-44 rounded-lg border bg-popover px-3 py-2 text-xs shadow-md">
                <p className="mb-1.5 font-semibold text-popover-foreground">
                    {hourly
                        ? `${formatDay(filters.from, language)} · ${row.label}`
                        : formatDay(row.bucket, language, 'long')}
                </p>
                {metric === 'revenue' ? (
                    <>
                        <TooltipRow
                            swatch="bg-primary"
                            label={t('dashboards.trend.current')}
                            value={formatBRL(row.revenue, intlLocale(language))}
                        />
                        <TooltipRow
                            swatch="bg-muted-foreground/60"
                            label={row.previousLabel}
                            value={formatBRL(
                                row.previous_revenue,
                                intlLocale(language),
                            )}
                        />
                        <p className="mt-1.5 text-muted-foreground">
                            {t('dashboards.trend.tables_count', {
                                count: row.tables_count,
                            })}
                        </p>
                    </>
                ) : (
                    <TooltipRow
                        swatch="bg-primary"
                        label={t('dashboards.tables_closed')}
                        value={String(row.tables_count)}
                    />
                )}
            </div>
        );
    };

    return (
        <div className="rounded-2xl border bg-card p-4 shadow-xs">
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3 border-b border-dashed pb-3">
                <div>
                    <h3 className="font-receipt text-[11px] font-medium tracking-[0.14em] text-muted-foreground uppercase">
                        {hourly
                            ? t('dashboards.trend.title_hour')
                            : t('dashboards.trend.title_day')}
                    </h3>
                    {metric === 'revenue' && (
                        <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            <span className="inline-flex items-center gap-1.5">
                                <span className="h-0.5 w-4 rounded-full bg-primary" />
                                {t('dashboards.trend.current')}
                            </span>
                            <span className="inline-flex items-center gap-1.5">
                                <span className="h-0.5 w-4 rounded-full bg-muted-foreground/60" />
                                {t('dashboards.trend.previous')}
                            </span>
                        </div>
                    )}
                </div>
                <div
                    role="group"
                    aria-label={t('dashboards.trend.metric')}
                    className="inline-flex rounded-full bg-muted p-0.5"
                >
                    {(['revenue', 'tables'] as const).map((option) => (
                        <button
                            key={option}
                            type="button"
                            aria-pressed={metric === option}
                            onClick={() => setMetric(option)}
                            className={cn(
                                'rounded-full px-3 py-1 text-xs font-medium transition-colors',
                                metric === option
                                    ? 'bg-card text-foreground shadow-xs'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {t(`dashboards.trend.metric_${option}`)}
                        </button>
                    ))}
                </div>
            </div>

            <div className="h-64 w-full sm:h-72">
                <ResponsiveContainer width="100%" height="100%">
                    {metric === 'revenue' ? (
                        <AreaChart
                            data={data}
                            margin={{ top: 8, right: 8, left: 0, bottom: 0 }}
                        >
                            <defs>
                                <linearGradient
                                    id={gradientId}
                                    x1="0"
                                    y1="0"
                                    x2="0"
                                    y2="1"
                                >
                                    <stop
                                        offset="0%"
                                        stopColor="var(--primary)"
                                        stopOpacity={0.28}
                                    />
                                    <stop
                                        offset="100%"
                                        stopColor="var(--primary)"
                                        stopOpacity={0}
                                    />
                                </linearGradient>
                            </defs>
                            <CartesianGrid
                                vertical={false}
                                stroke="var(--border)"
                                strokeWidth={1}
                            />
                            <XAxis
                                dataKey="label"
                                tick={AXIS_TICK}
                                tickLine={false}
                                axisLine={false}
                                interval={interval}
                                minTickGap={8}
                            />
                            <YAxis
                                tick={AXIS_TICK}
                                tickLine={false}
                                axisLine={false}
                                width={64}
                                tickFormatter={(value: number) =>
                                    formatCompactBRL(value, language)
                                }
                            />
                            <Tooltip
                                content={tooltip}
                                cursor={{
                                    stroke: 'var(--muted-foreground)',
                                    strokeWidth: 1,
                                }}
                            />
                            <Line
                                type="monotone"
                                dataKey="previous_revenue"
                                stroke="var(--muted-foreground)"
                                strokeOpacity={0.55}
                                strokeWidth={1.5}
                                dot={false}
                                activeDot={false}
                                isAnimationActive={false}
                            />
                            <Area
                                type="monotone"
                                dataKey="revenue"
                                stroke="var(--primary)"
                                strokeWidth={2}
                                fill={`url(#${gradientId})`}
                                dot={false}
                                activeDot={{
                                    r: 4,
                                    fill: 'var(--primary)',
                                    stroke: 'var(--card)',
                                    strokeWidth: 2,
                                }}
                                isAnimationActive={false}
                            />
                        </AreaChart>
                    ) : (
                        <BarChart
                            data={data}
                            margin={{ top: 8, right: 8, left: 0, bottom: 0 }}
                        >
                            <CartesianGrid
                                vertical={false}
                                stroke="var(--border)"
                            />
                            <XAxis
                                dataKey="label"
                                tick={AXIS_TICK}
                                tickLine={false}
                                axisLine={false}
                                interval={interval}
                                minTickGap={8}
                            />
                            <YAxis
                                tick={AXIS_TICK}
                                tickLine={false}
                                axisLine={false}
                                width={40}
                                allowDecimals={false}
                            />
                            <Tooltip
                                content={tooltip}
                                cursor={{ fill: 'var(--muted)', opacity: 0.6 }}
                            />
                            <Bar
                                dataKey="tables_count"
                                fill="var(--primary)"
                                radius={[4, 4, 0, 0]}
                                maxBarSize={28}
                                isAnimationActive={false}
                            />
                        </BarChart>
                    )}
                </ResponsiveContainer>
            </div>
        </div>
    );
}

function TooltipRow({
    swatch,
    label,
    value,
}: {
    swatch: string;
    label: string;
    value: string;
}) {
    return (
        <div className="flex items-center gap-2 py-0.5">
            <span className={cn('size-2 shrink-0 rounded-full', swatch)} />
            <span className="flex-1 text-muted-foreground">{label}</span>
            <span className="font-semibold text-popover-foreground tabular-nums">
                {value}
            </span>
        </div>
    );
}
