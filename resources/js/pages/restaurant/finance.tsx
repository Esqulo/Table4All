import { Form, Head, router, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    CircleDashed,
    ExternalLink,
    Landmark,
    Unlink,
} from 'lucide-react';
import type { ReactNode } from 'react';
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

type MercadoPagoAccount = {
    available: boolean;
    connected: boolean;
};

type Provider = 'stripe' | 'mercadopago';

type OnlinePaymentRow = {
    id: number;
    provider: Provider;
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
    mercadoPago: MercadoPagoAccount;
    provider: Provider | null;
    tier: string;
    tierName: string;
    feePercent: number;
    platformPaysStripeFees: boolean;
    payments: OnlinePaymentRow[];
};

export default function Finance({
    account,
    mercadoPago,
    provider,
    tierName,
    feePercent,
    platformPaysStripeFees,
    payments,
}: Props) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language === 'pt_BR' ? 'pt-BR' : 'en-US';
    const fmt = (n: number) => formatBRL(n, locale);
    // Managers may look at the finances; only the owner links or unlinks accounts.
    const isOwner =
        usePage<{ auth: Auth }>().props.auth.user.account_type === 'restaurant';

    const state = account.charges_enabled
        ? 'active'
        : account.connected
          ? 'pending'
          : 'none';
    const bothActive = account.charges_enabled && mercadoPago.connected;

    const choose = (next: Provider) =>
        router.put(FinanceController.chooseProvider.url(), { provider: next });

    const disconnectMercadoPago = () => {
        if (window.confirm(t('finance.mp_disconnect_confirm'))) {
            router.delete(FinanceController.disconnectMercadoPago.url());
        }
    };

    // On an active account: "receiving" when new payments go there, otherwise a button to send them there.
    const receivingControl = (which: Provider) =>
        provider === which ? (
            <Badge>{t('finance.receiving')}</Badge>
        ) : isOwner ? (
            <Button size="sm" variant="outline" onClick={() => choose(which)}>
                {t('finance.use_provider')}
            </Button>
        ) : null;

    return (
        <>
            <Head title={t('finance.title')} />

            <div className="max-w-3xl space-y-6 p-6">
                <Heading
                    title={t('finance.title')}
                    description={t('finance.description')}
                />

                <section className="flex items-start gap-3 rounded-xl border border-border bg-card p-5">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted">
                        {provider ? (
                            <CheckCircle2 className="h-5 w-5 text-green-600" />
                        ) : (
                            <CircleDashed className="h-5 w-5 text-muted-foreground" />
                        )}
                    </span>
                    <div className="min-w-0 flex-1 space-y-1">
                        <p className="font-semibold">
                            {t(
                                provider
                                    ? 'finance.status_on_title'
                                    : 'finance.status_off_title',
                            )}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {provider
                                ? t('finance.status_on_description', {
                                      provider: t(
                                          `finance.provider_${provider}`,
                                      ),
                                  })
                                : t('finance.status_off_description')}
                        </p>
                        {bothActive && (
                            <p className="text-xs text-muted-foreground">
                                {t('finance.choose_hint')}
                            </p>
                        )}
                    </div>
                </section>

                <div className="grid gap-4 md:grid-cols-2">
                    {mercadoPago.available && (
                        <ProviderCard
                            name={t('finance.provider_mercadopago')}
                            state={mercadoPago.connected ? 'active' : 'none'}
                            title={t(
                                mercadoPago.connected
                                    ? 'finance.mp_active_title'
                                    : 'finance.mp_none_title',
                            )}
                            description={t(
                                mercadoPago.connected
                                    ? 'finance.mp_active_description'
                                    : 'finance.mp_none_description',
                            )}
                            badge={
                                mercadoPago.connected
                                    ? receivingControl('mercadopago')
                                    : null
                            }
                        >
                            {isOwner && !mercadoPago.connected && (
                                <Form
                                    {...FinanceController.connectMercadoPago.form()}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            <Landmark className="h-4 w-4" />
                                            {t('finance.mp_connect')}
                                        </Button>
                                    )}
                                </Form>
                            )}
                            {mercadoPago.connected && (
                                <Button variant="outline" asChild>
                                    <a
                                        href="https://www.mercadopago.com.br/activities"
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <ExternalLink className="h-4 w-4" />
                                        {t('finance.mp_open')}
                                    </a>
                                </Button>
                            )}
                            {isOwner && mercadoPago.connected && (
                                <Button
                                    variant="ghost"
                                    onClick={disconnectMercadoPago}
                                >
                                    <Unlink className="h-4 w-4" />
                                    {t('finance.mp_disconnect')}
                                </Button>
                            )}
                        </ProviderCard>
                    )}

                    <ProviderCard
                        name={t('finance.provider_stripe')}
                        state={state}
                        title={t(`finance.state_${state}_title`)}
                        description={t(`finance.state_${state}_description`)}
                        badge={
                            state === 'active'
                                ? receivingControl('stripe')
                                : null
                        }
                    >
                        {isOwner && state !== 'active' && (
                            <Form {...FinanceController.connect.form()}>
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        variant={
                                            mercadoPago.available
                                                ? 'outline'
                                                : 'default'
                                        }
                                    >
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
                    </ProviderCard>
                </div>

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
                                {tierName}
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
                                            <span className="font-normal text-muted-foreground">
                                                {' · '}
                                                {t(
                                                    `finance.provider_${p.provider}`,
                                                )}
                                            </span>
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

/** One payment provider: the account's state, actions and whether it receives new payments. */
function ProviderCard({
    name,
    state,
    title,
    description,
    badge,
    children,
}: {
    name: string;
    state: 'active' | 'pending' | 'none';
    title: string;
    description: string;
    badge: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5">
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
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {name}
                        </p>
                        {badge}
                    </div>
                    <p className="font-semibold">{title}</p>
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
            </div>
            <div className="mt-auto flex flex-wrap gap-2">{children}</div>
        </section>
    );
}

Finance.layout = {
    breadcrumbs: [
        { title: 'finance.title', href: FinanceController.show.url() },
    ],
};
