import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';
import { change, formatPercent } from './format';

/**
 * A headline figure with its change against the previous period. The arrow
 * and the sign carry the direction, so it never relies on color alone.
 * `neutral` figures (like time spent at the table) aren't good or bad.
 */
export default function KpiCard({
    icon,
    label,
    value,
    current,
    previous,
    neutral = false,
    hint,
}: {
    icon: ReactNode;
    label: string;
    value: string;
    current: number;
    previous: number;
    neutral?: boolean;
    hint?: string;
}) {
    const { t, i18n } = useTranslation();
    const delta = change(current, previous);
    const flat = delta !== null && Math.abs(delta) < 0.005;
    const up = delta !== null && delta > 0;

    let badge: ReactNode;

    if (delta === null) {
        badge = (
            <span className="text-xs text-muted-foreground">
                {current > 0
                    ? t('dashboards.delta.new')
                    : t('dashboards.delta.none')}
            </span>
        );
    } else {
        const Icon = flat ? Minus : up ? ArrowUpRight : ArrowDownRight;
        badge = (
            <span
                className={cn(
                    'inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-xs font-semibold tabular-nums',
                    flat || neutral
                        ? 'bg-muted text-muted-foreground'
                        : up
                          ? 'bg-primary/12 text-primary'
                          : 'bg-destructive/10 text-destructive',
                )}
            >
                <Icon className="size-3.5" aria-hidden />
                {(up ? '+' : '') + formatPercent(delta, i18n.language)}
            </span>
        );
    }

    return (
        <div className="@container relative overflow-hidden rounded-2xl border bg-card p-4 shadow-xs">
            <div className="mb-3 flex items-center gap-2 text-muted-foreground">
                {icon}
                <span className="font-receipt text-[11px] tracking-[0.14em] uppercase">
                    {label}
                </span>
            </div>
            {/* Scales with the card, so "R$ 43.721,00" fits a narrow column. */}
            <div className="truncate font-display text-[min(2.4rem,12.5cqw)] leading-none font-extrabold tabular-nums">
                {value}
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-2">
                {badge}
                <span className="text-xs text-muted-foreground">
                    {hint ?? t('dashboards.delta.vs_previous')}
                </span>
            </div>
        </div>
    );
}
