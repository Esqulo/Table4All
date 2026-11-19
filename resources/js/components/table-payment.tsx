import { router } from '@inertiajs/react';
import {
    Banknote,
    Check,
    CreditCard,
    ExternalLink,
    Loader2,
    QrCode,
    Smartphone,
    Tag,
    Wallet,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import PaymentController from '@/actions/App/Http/Controllers/Customer/PaymentController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatBRL } from '@/lib/money';
import { newRequestKey } from '@/lib/request-key';
import { cn } from '@/lib/utils';
import type { PaymentMethod } from '@/types';

export type TablePaymentEntry = {
    method: PaymentMethod;
    amount: number;
    payer: string | null;
    by_staff: boolean;
    status: 'paid' | 'processing';
    created_at: string | null;
};

export type OpenCheckout = {
    id: number;
    amount: number;
    charge: number;
    url: string;
};

export type CouponQuote = {
    code: string;
    valid: boolean;
    error: string | null;
    discount: number;
    charge: number;
};

const METHOD_ICONS: Record<PaymentMethod, React.ReactNode> = {
    cash: <Banknote className="h-4 w-4" />,
    pix: <QrCode className="h-4 w-4" />,
    card: <CreditCard className="h-4 w-4" />,
    coupon: <Tag className="h-4 w-4" />,
    online: <Smartphone className="h-4 w-4" />,
};

const toCents = (value: number) => Math.round(value * 100);

/**
 * Paying (part of) the bill in the app: the customer picks an amount (or the
 * whole balance), may apply a coupon, and is sent to Stripe's checkout.
 */
export function PayPanel({
    code,
    remaining,
    enabled,
    minimum,
    myCheckout,
    couponQuote,
}: {
    code: string;
    remaining: number;
    enabled: boolean;
    minimum: number;
    myCheckout: OpenCheckout | null;
    couponQuote: CouponQuote | undefined;
}) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language === 'pt_BR' ? 'pt-BR' : 'en-US';
    const fmt = (n: number) => formatBRL(n, locale);

    const [cents, setCents] = useState(0);
    const [couponOpen, setCouponOpen] = useState(false);
    const [couponCode, setCouponCode] = useState('');
    // The code whose quote is shown; cleared when the customer removes it.
    const [appliedCode, setAppliedCode] = useState<string | null>(null);
    const [quoting, setQuoting] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [canceling, setCanceling] = useState(false);
    const [errors, setErrors] = useState<{ amount?: string; coupon?: string }>(
        {},
    );
    // One key per payment attempt: a re-sent request reopens the same checkout.
    const [requestKey, setRequestKey] = useState(newRequestKey);

    const remainingCents = toCents(remaining);
    const quote =
        appliedCode !== null && couponQuote?.code === appliedCode
            ? couponQuote
            : undefined;
    const discount = quote?.valid ? quote.discount : 0;
    const charge = Math.max(0, cents / 100 - discount);

    function requestQuote(code: string, amountCents: number) {
        setQuoting(true);
        router.reload({
            only: ['couponQuote'],
            data: { coupon: code, amount: (amountCents / 100).toFixed(2) },
            preserveUrl: true,
            onFinish: () => setQuoting(false),
        });
    }

    // The discount depends on the amount: quote again (debounced) when it changes.
    useEffect(() => {
        if (appliedCode === null || cents === 0) {
            return;
        }

        const timer = setTimeout(() => requestQuote(appliedCode, cents), 400);

        return () => clearTimeout(timer);
    }, [cents, appliedCode]);

    function changeAmount(next: number) {
        setCents(Math.min(next, 9_999_999));
        setRequestKey(newRequestKey());
        setErrors((e) => ({ ...e, amount: undefined }));
    }

    function applyCoupon() {
        const normalized = couponCode.trim().toUpperCase();

        if (normalized === '') {
            return;
        }

        // The effect above asks the server for the discount.
        setErrors((e) => ({ ...e, coupon: undefined }));
        setAppliedCode(normalized);
        setRequestKey(newRequestKey());
    }

    function removeCoupon() {
        setAppliedCode(null);
        setCouponCode('');
        setCouponOpen(false);
        setRequestKey(newRequestKey());
        setErrors((e) => ({ ...e, coupon: undefined }));
    }

    function pay() {
        if (submitting || cents === 0) {
            return;
        }

        setSubmitting(true);
        router.post(
            PaymentController.store.url(code),
            {
                amount: (cents / 100).toFixed(2),
                coupon: quote?.valid ? appliedCode : null,
                request_key: requestKey,
            },
            {
                preserveScroll: true,
                onError: (e) => {
                    setErrors(e);
                    setSubmitting(false);
                },
                // On success the browser leaves for Stripe; keep the button busy until then.
                onCancel: () => setSubmitting(false),
            },
        );
    }

    function cancelCheckout() {
        if (myCheckout === null || canceling) {
            return;
        }

        setCanceling(true);
        router.post(
            PaymentController.cancel.url(myCheckout.id),
            {},
            { preserveScroll: true, onFinish: () => setCanceling(false) },
        );
    }

    if (!enabled) {
        return (
            <div className="flex items-start gap-3 rounded-xl border border-dashed border-border p-4 text-sm text-muted-foreground">
                <Wallet className="mt-0.5 h-4 w-4 shrink-0" />
                <p>{t('payments.unavailable')}</p>
            </div>
        );
    }

    if (myCheckout !== null) {
        return (
            <div className="space-y-3 rounded-xl border border-primary/40 bg-primary/5 p-4">
                <p className="flex items-center gap-2 text-sm font-semibold">
                    <Wallet className="h-4 w-4 text-primary" />
                    {t('payments.open_checkout_title')}
                </p>
                <p className="text-sm text-muted-foreground">
                    {t('payments.open_checkout_description', {
                        amount: fmt(myCheckout.charge),
                    })}
                </p>
                <div className="grid grid-cols-2 gap-2">
                    <Button
                        variant="outline"
                        disabled={canceling}
                        onClick={cancelCheckout}
                    >
                        {t('payments.cancel_checkout')}
                    </Button>
                    <Button asChild>
                        <a href={myCheckout.url}>
                            <ExternalLink className="h-4 w-4" />
                            {t('payments.resume_checkout')}
                        </a>
                    </Button>
                </div>
            </div>
        );
    }

    if (remainingCents <= 0) {
        return null;
    }

    const displayValue =
        cents > 0
            ? (cents / 100).toLocaleString('pt-BR', {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2,
              })
            : '';

    return (
        <div className="space-y-4 rounded-xl border border-border bg-card p-4">
            <p className="flex items-center gap-2 text-sm font-semibold">
                <Wallet className="h-4 w-4 text-primary" />
                {t('payments.title')}
            </p>

            <div className="space-y-1.5">
                <label
                    htmlFor="pay-amount"
                    className="text-xs font-medium text-muted-foreground"
                >
                    {t('payments.amount_label')}
                </label>
                <div className="flex gap-2">
                    <div className="relative flex-1">
                        <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground">
                            R$
                        </span>
                        <Input
                            id="pay-amount"
                            type="text"
                            inputMode="numeric"
                            autoComplete="off"
                            placeholder="0,00"
                            value={displayValue}
                            onChange={(e) => {
                                const digits = e.target.value.replace(
                                    /\D/g,
                                    '',
                                );
                                changeAmount(
                                    digits === '' ? 0 : parseInt(digits, 10),
                                );
                            }}
                            className="pl-9 text-base tabular-nums"
                            aria-invalid={errors.amount ? true : undefined}
                        />
                    </div>
                    <Button
                        type="button"
                        variant={
                            cents === remainingCents ? 'secondary' : 'outline'
                        }
                        onClick={() => changeAmount(remainingCents)}
                    >
                        {t('payments.pay_all')}
                    </Button>
                </div>
                {errors.amount ? (
                    <p className="text-xs text-destructive">{errors.amount}</p>
                ) : (
                    <p className="text-xs text-muted-foreground">
                        {t('payments.amount_hint', {
                            remaining: fmt(remaining),
                            min: fmt(minimum),
                        })}
                    </p>
                )}
            </div>

            {appliedCode === null ? (
                couponOpen ? (
                    <div className="space-y-1.5">
                        <label
                            htmlFor="pay-coupon"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            {t('payments.coupon_label')}
                        </label>
                        <div className="flex gap-2">
                            <Input
                                id="pay-coupon"
                                value={couponCode}
                                autoFocus
                                autoComplete="off"
                                autoCapitalize="characters"
                                maxLength={40}
                                placeholder={t('payments.coupon_placeholder')}
                                onChange={(e) => setCouponCode(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        applyCoupon();
                                    }
                                }}
                                className="uppercase"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                onClick={applyCoupon}
                                disabled={couponCode.trim() === ''}
                            >
                                {t('payments.coupon_apply')}
                            </Button>
                        </div>
                    </div>
                ) : (
                    <button
                        type="button"
                        onClick={() => setCouponOpen(true)}
                        className="flex items-center gap-1.5 text-xs font-medium text-primary hover:underline"
                    >
                        <Tag className="h-3.5 w-3.5" />
                        {t('payments.have_coupon')}
                    </button>
                )
            ) : (
                <div
                    className={cn(
                        'flex items-start gap-2 rounded-lg border px-3 py-2 text-sm',
                        quote && !quote.valid
                            ? 'border-destructive/40 bg-destructive/5'
                            : 'border-green-600/30 bg-green-600/5',
                    )}
                >
                    {quoting || (!quote && cents > 0) ? (
                        <Loader2 className="mt-0.5 h-4 w-4 shrink-0 animate-spin text-muted-foreground" />
                    ) : quote && !quote.valid ? (
                        <X className="mt-0.5 h-4 w-4 shrink-0 text-destructive" />
                    ) : (
                        <Check className="mt-0.5 h-4 w-4 shrink-0 text-green-600" />
                    )}
                    <div className="min-w-0 flex-1">
                        <p className="font-mono font-semibold">{appliedCode}</p>
                        <p className="text-xs text-muted-foreground">
                            {quote && !quote.valid
                                ? quote.error
                                : quote
                                  ? t('payments.coupon_discount', {
                                        discount: fmt(quote.discount),
                                    })
                                  : t('payments.coupon_needs_amount')}
                        </p>
                        {errors.coupon && (
                            <p className="text-xs text-destructive">
                                {errors.coupon}
                            </p>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={removeCoupon}
                        className="text-muted-foreground hover:text-foreground"
                        aria-label={t('payments.coupon_remove')}
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>
            )}

            {cents > 0 && (
                <div className="space-y-1 rounded-lg bg-muted px-3 py-2 text-sm">
                    {discount > 0 && (
                        <>
                            <div className="flex justify-between text-muted-foreground">
                                <span>{t('payments.off_the_bill')}</span>
                                <span className="tabular-nums">
                                    {fmt(cents / 100)}
                                </span>
                            </div>
                            <div className="flex justify-between text-green-600">
                                <span>{t('payments.discount')}</span>
                                <span className="tabular-nums">
                                    − {fmt(discount)}
                                </span>
                            </div>
                        </>
                    )}
                    <div className="flex items-baseline justify-between">
                        <span className="font-medium">
                            {t('payments.you_pay')}
                        </span>
                        <span className="text-lg font-bold tabular-nums">
                            {fmt(charge)}
                        </span>
                    </div>
                </div>
            )}

            <Button
                className="w-full"
                size="lg"
                disabled={
                    submitting ||
                    cents === 0 ||
                    (appliedCode !== null && (quoting || quote === undefined))
                }
                onClick={pay}
            >
                {submitting ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                    <CreditCard className="h-4 w-4" />
                )}
                {cents > 0
                    ? t('payments.pay_amount', { amount: fmt(charge) })
                    : t('payments.pay')}
            </Button>
            <p className="text-center text-[11px] text-muted-foreground">
                {t('payments.secure_note')}
            </p>
        </div>
    );
}

/** Every payment on the bill and who made it, visible to everyone at the table. */
export function PaymentsList({ payments }: { payments: TablePaymentEntry[] }) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language === 'pt_BR' ? 'pt-BR' : 'en-US';

    if (payments.length === 0) {
        return null;
    }

    return (
        <div className="space-y-2">
            <p className="text-sm font-semibold">{t('payments.list_title')}</p>
            <ul className="divide-y divide-border rounded-xl border border-border bg-card">
                {payments.map((payment, i) => (
                    <li key={i} className="flex items-center gap-3 px-4 py-2.5">
                        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                            {METHOD_ICONS[payment.method]}
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm">
                                {payment.by_staff
                                    ? t('payments.by_staff')
                                    : payment.payer}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {t(`tables.payment_methods.${payment.method}`)}
                                {payment.status === 'processing' &&
                                    ` · ${t('payments.processing')}`}
                            </p>
                        </div>
                        <span
                            className={cn(
                                'text-sm font-semibold tabular-nums',
                                payment.status === 'processing' &&
                                    'text-muted-foreground',
                            )}
                        >
                            {formatBRL(payment.amount, locale)}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
