import { router } from '@inertiajs/react';
import { CalendarRange, Tag } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import DashboardController from '@/actions/App/Http/Controllers/Restaurant/DashboardController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { DashboardFilters, DashboardPeriod } from '@/types';
import { addDays, daysBetween } from './format';

const PRESETS = ['today', '7', '30', '90'] as const;
const ALL = 'all';

type Query = {
    period: DashboardPeriod;
    from?: string;
    to?: string;
    category?: number;
};

export default function DashboardFiltersBar({
    filters,
    categories,
    onLoadingChange,
}: {
    filters: DashboardFilters;
    categories: { id: number; name: string }[];
    onLoadingChange: (loading: boolean) => void;
}) {
    const { t } = useTranslation();
    const [customOpen, setCustomOpen] = useState(filters.period === 'custom');
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);

    const visit = (query: Query) => {
        router.get(
            DashboardController.index.url({ query }),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => onLoadingChange(true),
                onFinish: () => onLoadingChange(false),
            },
        );
    };

    const current = (): Query => ({
        period: filters.period,
        ...(filters.period === 'custom'
            ? { from: filters.from, to: filters.to }
            : {}),
        ...(filters.category ? { category: filters.category } : {}),
    });

    const span = from && to ? daysBetween(from, to) : 0;
    const customError =
        !from || !to
            ? null
            : span < 1
              ? t('dashboards.filters.error_order')
              : span > filters.max_days
                ? t('dashboards.filters.error_max', {
                      count: filters.max_days,
                  })
                : null;

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <div
                    role="group"
                    aria-label={t('dashboards.filters.period')}
                    className="inline-flex flex-wrap rounded-full border bg-card p-1 shadow-xs"
                >
                    {PRESETS.map((preset) => (
                        <button
                            key={preset}
                            type="button"
                            aria-pressed={
                                filters.period === preset && !customOpen
                            }
                            onClick={() => {
                                setCustomOpen(false);
                                visit({
                                    ...current(),
                                    period: preset,
                                    from: undefined,
                                    to: undefined,
                                });
                            }}
                            className={cn(
                                'rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors',
                                filters.period === preset && !customOpen
                                    ? 'bg-primary text-primary-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {t(`dashboards.period_${preset}`)}
                        </button>
                    ))}
                    <button
                        type="button"
                        aria-pressed={customOpen}
                        aria-expanded={customOpen}
                        onClick={() => setCustomOpen((open) => !open)}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors',
                            customOpen
                                ? 'bg-primary text-primary-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        <CalendarRange className="size-4" />
                        {t('dashboards.period_custom')}
                    </button>
                </div>

                {categories.length > 0 && (
                    <Select
                        value={
                            filters.category ? String(filters.category) : ALL
                        }
                        onValueChange={(value) =>
                            visit({
                                ...current(),
                                category:
                                    value === ALL ? undefined : Number(value),
                            })
                        }
                    >
                        <SelectTrigger
                            className="h-10 min-w-44 rounded-full bg-card"
                            aria-label={t('dashboards.filters.category')}
                        >
                            <Tag className="size-4" />
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>
                                {t('dashboards.filters.all_categories')}
                            </SelectItem>
                            {categories.map((category) => (
                                <SelectItem
                                    key={category.id}
                                    value={String(category.id)}
                                >
                                    {category.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
            </div>

            {customOpen && (
                <form
                    className="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-3 shadow-xs"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (customError || !from || !to) {
                            return;
                        }

                        visit({ ...current(), period: 'custom', from, to });
                    }}
                >
                    <label className="grid gap-1 text-xs text-muted-foreground">
                        {t('dashboards.filters.from')}
                        <Input
                            type="date"
                            value={from}
                            max={to || filters.today}
                            min={
                                to
                                    ? addDays(to, -(filters.max_days - 1))
                                    : undefined
                            }
                            onChange={(event) => setFrom(event.target.value)}
                            className="h-9 w-40 dark:[color-scheme:dark]"
                            required
                        />
                    </label>
                    <label className="grid gap-1 text-xs text-muted-foreground">
                        {t('dashboards.filters.to')}
                        <Input
                            type="date"
                            value={to}
                            min={from || undefined}
                            max={filters.today}
                            onChange={(event) => setTo(event.target.value)}
                            className="h-9 w-40 dark:[color-scheme:dark]"
                            required
                        />
                    </label>
                    <Button
                        type="submit"
                        size="sm"
                        className="h-9"
                        disabled={customError !== null || !from || !to}
                    >
                        {t('dashboards.filters.apply')}
                    </Button>
                    <p
                        className={cn(
                            'basis-full text-xs',
                            customError
                                ? 'text-destructive'
                                : 'text-muted-foreground',
                        )}
                    >
                        {customError ??
                            t('dashboards.filters.max_hint', {
                                count: filters.max_days,
                            })}
                    </p>
                </form>
            )}
        </div>
    );
}
