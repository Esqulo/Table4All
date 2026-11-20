import { formatBRL } from '@/lib/money';

/** The service fee terms of a table (App\Support\ServiceFee::terms). */
export type ServiceFeeTerms = {
    type: 'percent' | 'fixed';
    /** A percentage (10 = 10%) or an amount in reais. */
    value: number;
    /** Most a percentage fee can reach, in reais. */
    cap: number | null;
    /** Staff took it off this table's bill. */
    waived: boolean;
};

/**
 * The fee in cents on `productCents` of products. Integer math, rounded half
 * up, exactly like the server (ServiceFee::cents): the screens and the check
 * that lets a table close must agree to the cent.
 */
export function serviceFeeCents(
    terms: ServiceFeeTerms | null | undefined,
    productCents: number,
): number {
    if (!terms || terms.waived || productCents <= 0) {
        return 0;
    }

    // Basis points for a percentage, cents for a fixed amount.
    const units = Math.round(terms.value * 100);

    if (units <= 0) {
        return 0;
    }

    if (terms.type === 'fixed') {
        return units;
    }

    const fee = Math.floor((productCents * units + 5000) / 10000);

    return terms.cap === null
        ? fee
        : Math.min(fee, Math.round(terms.cap * 100));
}

/** "10%" or "R$ 5,00", for the label next to the fee. */
export function serviceFeeRate(
    terms: ServiceFeeTerms,
    locale = 'pt-BR',
): string {
    if (terms.type === 'fixed') {
        return formatBRL(terms.value, locale);
    }

    return `${terms.value.toLocaleString(locale, { maximumFractionDigits: 2 })}%`;
}
