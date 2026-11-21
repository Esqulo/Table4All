import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    formatCompactBRL,
    formatDay,
    formatNumber,
    intlLocale,
} from '@/components/dashboard/format';
import { formatBRL } from '@/lib/money';
import { cn } from '@/lib/utils';

export type DailyPoint = {
    day: string;
    revenue: number;
    tables: number;
    items: number;
    signups?: number;
};

type Metric = 'revenue' | 'tables' | 'items' | 'signups';

const AXIS_TICK = { fontSize: 11, fill: 'var(--muted-foreground)' };

/**
 * The last 30 days, one measure at a time (amount paid as an area, counts
 * as bars): two measures never share an axis, the toggle swaps the chart.
 */
export default function DailyChart({
    points,
    title,
}: {
    points: DailyPoint[];
    title: string;
}) {
    const { t, i18n } = useTranslation();
    const language = i18n.language;
    const [metric, setMetric] = useState<Metric>('revenue');
    const gradientId = useId().replace(/:/g, '');
    const metrics: Metric[] = [
        'revenue',
        'tables',
        'items',
        ...(points[0]?.signups !== undefined ? (['signups'] as const) : []),
    ];

    const data = points.map((p) => ({
        ...p,
        label: formatDay(p.day, language),
        value: p[metric] ?? 0,
    }));
    type Row = (typeof data)[number];

    const format = (value: number) =>
        metric === 'revenue'
            ? formatBRL(value, intlLocale(language))
            : formatNumber(value, language);

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
            <div className="min-w-40 rounded-lg border bg-popover px-3 py-2 text-xs shadow-md">
                <p className="mb-1 font-semibold text-popover-foreground">
                    {formatDay(row.day, language, 'long')}
                </p>
                <div className="flex items-center gap-2">
                    <span className="size-2 shrink-0 rounded-full bg-primary" />
                    <span className="flex-1 text-muted-foreground">
                        {t(`admin_overview.metric_${metric}`)}
                    </span>
                    <span className="font-semibold text-popover-foreground tabular-nums">
                        {format(row.value)}
                    </span>
                </div>
            </div>
        );
    };

    const axes = (
        <>
            <CartesianGrid vertical={false} stroke="var(--border)" />
            <XAxis
                dataKey="label"
                tick={AXIS_TICK}
                tickLine={false}
                axisLine={false}
                interval={Math.max(0, Math.ceil(data.length / 8) - 1)}
                minTickGap={8}
            />
            <YAxis
                tick={AXIS_TICK}
                tickLine={false}
                axisLine={false}
                width={metric === 'revenue' ? 64 : 40}
                allowDecimals={metric === 'revenue'}
                tickFormatter={(value: number) =>
                    metric === 'revenue'
                        ? formatCompactBRL(value, language)
                        : formatNumber(value, language)
                }
            />
        </>
    );

    return (
        <div className="rounded-2xl border bg-card p-4 shadow-xs">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3 border-b border-dashed pb-3">
                <h3 className="font-receipt text-[11px] font-medium tracking-[0.14em] text-muted-foreground uppercase">
                    {title}
                </h3>
                <div
                    role="group"
                    aria-label={t('admin_overview.metric')}
                    className="inline-flex flex-wrap rounded-full bg-muted p-0.5"
                >
                    {metrics.map((option) => (
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
                            {t(`admin_overview.metric_${option}`)}
                        </button>
                    ))}
                </div>
            </div>

            <div className="h-64 w-full">
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
                            {axes}
                            <Tooltip
                                content={tooltip}
                                cursor={{
                                    stroke: 'var(--muted-foreground)',
                                    strokeWidth: 1,
                                }}
                            />
                            <Area
                                type="monotone"
                                dataKey="value"
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
                            {axes}
                            <Tooltip
                                content={tooltip}
                                cursor={{ fill: 'var(--muted)', opacity: 0.6 }}
                            />
                            <Bar
                                dataKey="value"
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
