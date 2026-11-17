const formatters = new Map<string, Intl.NumberFormat>();

/**
 * Formats a BRL amount. Building an Intl.NumberFormat is expensive on old
 * devices, and lists call this once per row on every refresh, so one
 * formatter per locale is created and reused.
 */
export function formatBRL(value: number, locale = 'pt-BR'): string {
    let formatter = formatters.get(locale);

    if (!formatter) {
        formatter = new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: 'BRL',
        });
        formatters.set(locale, formatter);
    }

    return formatter.format(value);
}
