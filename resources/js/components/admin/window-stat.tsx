import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { formatNumber } from '@/components/dashboard/format';

export type Windows = { day: number; week: number; month: number };

/**
 * One figure over three rolling windows (24 h, 7 days, 30 days), side by
 * side: how many people signed in, were active or signed up.
 */
export default function WindowStat({
    icon,
    label,
    windows,
    hint,
}: {
    icon: ReactNode;
    label: string;
    windows: Windows;
    hint?: string;
}) {
    const { t, i18n } = useTranslation();

    return (
        <div className="rounded-2xl border bg-card p-4 shadow-xs">
            <div className="mb-3 flex items-center gap-2 text-muted-foreground">
                {icon}
                <span className="font-receipt text-[11px] tracking-[0.14em] uppercase">
                    {label}
                </span>
            </div>
            <dl className="grid grid-cols-3 divide-x divide-dashed">
                {(['day', 'week', 'month'] as const).map((key) => (
                    <div key={key} className="px-2 first:pl-0 last:pr-0">
                        <dd className="font-display text-2xl leading-none font-extrabold tabular-nums">
                            {formatNumber(windows[key], i18n.language)}
                        </dd>
                        <dt className="mt-1.5 text-xs text-muted-foreground">
                            {t(`admin_overview.window_${key}`)}
                        </dt>
                    </div>
                ))}
            </dl>
            {hint && (
                <p className="mt-3 text-xs text-muted-foreground">{hint}</p>
            )}
        </div>
    );
}

/** A plain figure with a caption, for counts that have no period to compare. */
export function PlainStat({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint?: string;
}) {
    return (
        <div className="rounded-xl border bg-card px-4 py-3">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="mt-1 font-display text-xl font-extrabold tabular-nums">
                {value}
            </p>
            {hint && (
                <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>
            )}
        </div>
    );
}
