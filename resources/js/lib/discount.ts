import { formatBRL } from '@/lib/money';

export type Discount = {
    discount_type: 'percent' | 'fixed';
    discount_value: number;
    max_discount: number | null;
};

/** "R$ 5,00", "10%" or "10% (até R$ 20,00)": a coupon or loyalty tier's discount. */
export function formatDiscount(
    discount: Discount,
    t: (key: string, options?: Record<string, unknown>) => string,
    locale = 'pt-BR',
): string {
    if (discount.discount_type === 'fixed') {
        return formatBRL(discount.discount_value, locale);
    }

    const value = `${discount.discount_value.toLocaleString(locale)}%`;

    return discount.max_discount !== null
        ? t('incentives.discount_capped', {
              value,
              max: formatBRL(discount.max_discount, locale),
          })
        : value;
}
