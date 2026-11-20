import { useTranslation } from 'react-i18next';
import { formatBRL } from '@/lib/money';
import type { PaymentMethod, PaymentMethodTotal } from '@/types';
import { formatPercent, intlLocale } from './format';

/**
 * Color follows the method, never its rank, so "Pix" keeps its color
 * whatever else is (or isn't) in the period.
 */
const METHOD_COLOR: Record<PaymentMethod, string> = {
    pix: 'var(--chart-1)',
    card: 'var(--chart-2)',
    cash: 'var(--chart-3)',
    online: 'var(--chart-4)',
    coupon: 'var(--chart-5)',
};

/** Part-to-whole as one segmented bar, with the figures in a legend list. */
export default function PaymentShare({
    methods,
}: {
    methods: PaymentMethodTotal[];
}) {
    const { t, i18n } = useTranslation();
    const locale = intlLocale(i18n.language);
    const total = methods.reduce((sum, m) => sum + m.total, 0);

    if (methods.length === 0 || total <= 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('dashboards.no_data')}
            </p>
        );
    }

    return (
        <div>
            <div
                className="flex h-3 w-full gap-[2px] overflow-hidden rounded-full"
                role="img"
                aria-label={t('dashboards.payment_methods')}
            >
                {methods.map((m) => (
                    <div
                        key={m.method}
                        className="h-full first:rounded-l-full last:rounded-r-full"
                        style={{
                            width: `${(m.total / total) * 100}%`,
                            backgroundColor: METHOD_COLOR[m.method],
                        }}
                        title={`${t(`tables.payment_methods.${m.method}`)}: ${formatBRL(m.total, locale)}`}
                    />
                ))}
            </div>
            <ul className="mt-4 divide-y">
                {methods.map((m) => (
                    <li
                        key={m.method}
                        className="flex items-center gap-3 py-2 text-sm"
                    >
                        <span
                            className="size-2.5 shrink-0 rounded-full"
                            style={{ backgroundColor: METHOD_COLOR[m.method] }}
                        />
                        <span className="flex-1">
                            {t(`tables.payment_methods.${m.method}`)}
                        </span>
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {`${t('dashboards.payments_count', { count: m.count })} · ${formatPercent(m.total / total, i18n.language)}`}
                        </span>
                        <span className="w-24 text-right font-semibold tabular-nums">
                            {formatBRL(m.total, locale)}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
