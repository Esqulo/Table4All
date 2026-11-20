import type { DashboardFilters } from '@/types';

const cache = new Map<string, Intl.DateTimeFormat | Intl.NumberFormat>();

function cached<T extends Intl.DateTimeFormat | Intl.NumberFormat>(
    key: string,
    build: () => T,
): T {
    let formatter = cache.get(key) as T | undefined;

    if (!formatter) {
        formatter = build();
        cache.set(key, formatter);
    }

    return formatter;
}

/** i18next language (pt_BR) to an Intl locale (pt-BR). */
export function intlLocale(language: string): string {
    return language.replace('_', '-');
}

/** A Y-m-d calendar day, formatted without any timezone shift. */
export function formatDay(
    day: string,
    language: string,
    style: 'short' | 'long' = 'short',
): string {
    const locale = intlLocale(language);

    return cached(
        `day:${locale}:${style}`,
        () =>
            new Intl.DateTimeFormat(
                locale,
                style === 'short'
                    ? { day: '2-digit', month: 'short', timeZone: 'UTC' }
                    : {
                          weekday: 'short',
                          day: '2-digit',
                          month: 'short',
                          timeZone: 'UTC',
                      },
            ),
    )
        .format(new Date(`${day}T00:00:00Z`))
        .replace('.', '');
}

export function formatDateTime(iso: string, language: string): string {
    const locale = intlLocale(language);

    return cached(
        `datetime:${locale}`,
        () =>
            new Intl.DateTimeFormat(locale, {
                day: '2-digit',
                month: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
            }),
    ).format(new Date(iso));
}

/** Short weekday name for an ISO weekday (1 = Monday … 7 = Sunday). */
export function weekdayName(isoWeekday: number, language: string): string {
    const locale = intlLocale(language);
    // 2024-01-01 was a Monday.
    const date = new Date(Date.UTC(2024, 0, isoWeekday));

    return cached(
        `weekday:${locale}`,
        () =>
            new Intl.DateTimeFormat(locale, {
                weekday: 'short',
                timeZone: 'UTC',
            }),
    )
        .format(date)
        .replace('.', '');
}

/** Compact currency for chart axes: R$ 1,2 mil. */
export function formatCompactBRL(value: number, language: string): string {
    const locale = intlLocale(language);

    return cached(
        `compact:${locale}`,
        () =>
            new Intl.NumberFormat(locale, {
                style: 'currency',
                currency: 'BRL',
                notation: 'compact',
                maximumFractionDigits: 1,
            }),
    ).format(value);
}

export function formatNumber(value: number, language: string): string {
    const locale = intlLocale(language);

    return cached(
        `number:${locale}`,
        () => new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }),
    ).format(value);
}

export function formatPercent(value: number, language: string): string {
    const locale = intlLocale(language);

    return cached(
        `percent:${locale}`,
        () =>
            new Intl.NumberFormat(locale, {
                style: 'percent',
                maximumFractionDigits: 0,
            }),
    ).format(value);
}

/** Y-m-d plus a number of days, in calendar terms (no timezone involved). */
export function addDays(day: string, days: number): string {
    const date = new Date(`${day}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

/** Inclusive number of days between two Y-m-d dates. */
export function daysBetween(from: string, to: string): number {
    return (
        Math.round(
            (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) /
                86_400_000,
        ) + 1
    );
}

/** Human label of a timeline bucket: "14 nov" for days, "13h" for hours. */
export function bucketLabel(
    bucket: string,
    granularity: DashboardFilters['granularity'],
    language: string,
): string {
    return granularity === 'hour' ? `${bucket}h` : formatDay(bucket, language);
}

/**
 * Relative change from the previous period, or null when there's nothing
 * to compare with (the previous period was zero).
 */
export function change(current: number, previous: number): number | null {
    if (previous === 0) {
        return null;
    }

    return (current - previous) / previous;
}
