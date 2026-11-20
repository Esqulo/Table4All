import { Form, Head, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    CircleDashed,
    ExternalLink,
    Landmark,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import FinanceController from '@/actions/App/Http/Controllers/Restaurant/FinanceController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatBRL } from '@/lib/money';
import type { Auth } from '@/types';

type Account = {
    connected: boolean;
    charges_enabled: boolean;
    details_submitted: boolean;
};

type OnlinePaymentRow = {
    id: number;
    paid_at: string | null;
    table: string;
    payer: string | null;
    amount: number;
    discount: number;
    coupon_by_platform: boolean;
    charge: number;
    platform_fee: number;
    fee_refund: number;
    owed: number;
    owed_paid: boolean;
    refunded: number;
};

type Props = {
    account: Account;
    tier: string;
    feePercent: number;
    platformPaysStripeFees: boolean;
    payments: OnlinePaymentRow[];
};

export default function Finance({
    account,
    tier,
    feePercent,
    platformPaysStripeFees,
    payments,
}: Props) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language === 'pt_BR' ? 'pt-BR' : 'en-US';
    const fmt = (n: number) => formatBRL(n, locale);
    // Managers may look at the finances; only the owner connects the Stripe account.
    const isOwner =
        usePage<{ auth: Auth }>().props.auth.user.account_type === 'restaurant';

    const state = account.charges_enabled
        ? 'active'
        : account.connected
          ? 'pending'
          : 'none';

    return (
        <>
            <Head title={t('finance.title')} />

            <div className="max-w-3xl space-y-6 p-6">
                <Heading
                    title={t('finance.title')}
                    description={t('finance.description')}
                />

                <section className="space-y-4 rounded-xl border border-border bg-card p-5">
                    <div className="flex items-start gap-3">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted">
                            {state === 'active' ? (
                                <CheckCircle2 className="h-5 w-5 text-green-600" />
                            ) : state === 'pending' ? (
                                <AlertCircle className="h-5 w-5 text-amber-500" />
                            ) : (
                                <CircleDashed className="h-5 w-5 text-muted-foreground" />
                            )}
                        </span>
                        <div className="min-w-0 flex-1 space-y-1">
                            <p className="font-semibold">
                                {t(`finance.state_${state}_title`)}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {t(`finance.state_${state}_description`)}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {isOwner && state !== 'active' && (
                            <Form {...FinanceController.connect.form()}>
                                {({ processing }) => (
                                    <Button type="submit" disabled={processing}>
                                        <Landmark className="h-4 w-4" />
                                        {state === 'none'
                                            ? t('finance.connect')
                                            : t('finance.continue')}
                                    </Button>
                                )}
                            </Form>
                        )}
                        {account.connected && (
                            <Button variant="outline" asChild>
                                <a
                                    href="https://dashboard.stripe.com"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <ExternalLink className="h-4 w-4" />
                                    {t('finance.open_stripe')}
                                </a>
                            </Button>
                        )}
                    </div>
                </section>

                <section className="space-y-3 rounded-xl border border-border bg-card p-5">
                    <p className="text-sm font-semibold">
                        {t('finance.terms_title')}
                    </p>
                    <dl className="grid gap-3 text-sm sm:grid-cols-2">
                        <div className="space-y-1 rounded-lg bg-muted px-3 py-2">
                            <dt className="text-xs text-muted-foreground">
                                {t('finance.plan')}
                            </dt>
                            <dd className="flex items-center gap-2 font-semibold">
                                {t(`payments.tiers.${tier}`, {
                                    defaultValue: tier,
                                })}
                                <Badge variant="secondary">
                                    {t('finance.fee_percent', {
                                        percent:
                                            feePercent.toLocaleString(locale),
                                    })}
                                </Badge>
                            </dd>
                        </div>
                        <div className="space-y-1 rounded-lg bg-muted px-3 py-2">
                            <dt className="text-xs text-muted-foreground">
                                {t('finance.stripe_fees')}
                            </dt>
                            <dd className="font-semibold">
                                {platformPaysStripeFees
                                    ? t('finance.stripe_fees_platform')
                                    : t('finance.stripe_fees_restaurant')}
                            </dd>
                        </div>
                    </dl>
                    <p className="text-xs text-muted-foreground">
                        {t('finance.terms_hint')}
                    </p>
                </section>

                <section className="space-y-2">
                    <p className="text-sm font-semibold">
                        {t('finance.payments_title')}
                    </p>
                    {payments.length === 0 ? (
                        <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                            {t('finance.payments_empty')}
                        </p>
                    ) : (
                        <ul className="divide-y divide-border rounded-xl border border-border bg-card">
                            {payments.map((p) => (
                                <li
                                    key={p.id}
                                    className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium">
                                            {p.table}
                                            {p.payer && (
                                                <span className="font-normal text-muted-foreground">
                                                    {' · '}
                                                    {p.payer}
                                                </span>
                                            )}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {p.paid_at &&
                                                new Date(
                                                    p.paid_at,
                                                ).toLocaleString(locale, {
                                                    dateStyle: 'short',
                                                    timeStyle: 'short',
                                                })}
                                            {p.discount > 0 &&
                                                ` · ${t(
                                                    p.coupon_by_platform
                                                        ? 'finance.coupon_platform'
                                                        : 'finance.coupon_restaurant',
                                                    {
                                                        amount: fmt(p.discount),
                                                    },
                                                )}`}
                                            {p.platform_fee > 0 &&
                                                ` · ${t('finance.fee_line', {
                                                    amount: fmt(p.platform_fee),
                                                })}`}
                                            {p.fee_refund > 0 &&
                                                ` · ${t(
                                                    'finance.fee_refund_line',
                                                    {
                                                        amount: fmt(
                                                            p.fee_refund,
                                                        ),
                                                    },
                                                )}`}
                                            {p.owed > 0 &&
                                                ` · ${t(
                                                    p.owed_paid
                                                        ? 'finance.owed_paid_line'
                                                        : 'finance.owed_line',
                                                    { amount: fmt(p.owed) },
                                                )}`}
                                            {p.refunded > 0 &&
                                                ` · ${t('finance.refund_line', {
                                                    amount: fmt(p.refunded),
                                                })}`}
                                        </p>
                                    </div>
                                    <span className="font-semibold tabular-nums">
                                        {fmt(p.amount)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

Finance.layout = {
    breadcrumbs: [
        { title: 'finance.title', href: FinanceController.show.url() },
    ],
};
