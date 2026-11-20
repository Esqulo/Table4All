import { Head, router } from '@inertiajs/react';
import { Flame, Gift, Ticket, Users, Wallet } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import IncentiveController from '@/actions/App/Http/Controllers/Restaurant/IncentiveController';
import Heading from '@/components/heading';
import TierEditor from '@/components/loyalty/tier-editor';
import { formatBRL } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { IncentiveSetting } from '@/types';

function Stat({
    icon: Icon,
    label,
    value,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
}) {
    return (
        <div className="rounded-xl bg-muted/60 p-3">
            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Icon className="size-3.5" />
                {label}
            </p>
            <p className="mt-1 text-lg font-bold tabular-nums">{value}</p>
        </div>
    );
}

function Switch({
    checked,
    disabled,
    onChange,
    label,
}: {
    checked: boolean;
    disabled: boolean;
    onChange: (checked: boolean) => void;
    label: string;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={cn(
                'relative inline-flex h-7 w-12 shrink-0 items-center rounded-full border-2 border-transparent transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-60',
                checked ? 'bg-primary' : 'bg-input',
            )}
        >
            <span
                className={cn(
                    'size-6 rounded-full bg-background shadow-sm transition-transform',
                    checked ? 'translate-x-5' : 'translate-x-0',
                )}
            />
        </button>
    );
}

function LoyaltyIncentive({ incentive }: { incentive: IncentiveSetting }) {
    const { t, i18n } = useTranslation();
    const [saving, setSaving] = useState(false);
    const { rules, stats } = incentive;

    const toggle = (enabled: boolean) => {
        router.put(
            IncentiveController.update.url(incentive.key),
            { enabled },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <article className="overflow-hidden rounded-2xl border bg-card shadow-xs">
            <div className="flex items-start gap-4 p-5">
                <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                    <Gift className="size-5" />
                </span>
                <div className="min-w-0 flex-1 space-y-1">
                    <h2 className="text-base font-semibold">
                        {t('incentives.loyalty.title')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {t('incentives.loyalty.description')}
                    </p>
                </div>
                <Switch
                    checked={incentive.enabled}
                    disabled={saving}
                    onChange={toggle}
                    label={t('incentives.loyalty.toggle')}
                />
            </div>

            <div className="space-y-4 border-t border-dashed p-5">
                <h3 className="text-sm font-semibold">
                    {t('incentives.tiers.title')}
                </h3>
                <TierEditor
                    tiers={incentive.tiers}
                    maxTiers={rules.max_tiers}
                    maxVisits={rules.max_visits}
                    disabled={!incentive.enabled}
                />

                <ul className="grid gap-x-10 gap-y-3 text-sm text-muted-foreground sm:grid-cols-2">
                    <li className="flex gap-2">
                        <Flame className="mt-0.5 size-4 shrink-0 text-orange-500" />
                        {t('incentives.loyalty.rule_visit')}
                    </li>
                    <li className="flex gap-2">
                        <Flame className="mt-0.5 size-4 shrink-0 text-orange-500" />
                        {t('incentives.loyalty.rule_streak', {
                            count: rules.streak_days,
                        })}
                    </li>
                    <li className="flex gap-2">
                        <Ticket className="mt-0.5 size-4 shrink-0 text-primary" />
                        {t('incentives.loyalty.rule_coupon', {
                            count: rules.reward_valid_days,
                        })}
                    </li>
                    <li className="flex gap-2">
                        <Wallet className="mt-0.5 size-4 shrink-0 text-primary" />
                        {t('incentives.loyalty.rule_cost')}
                    </li>
                </ul>

                <p className="text-xs text-muted-foreground">
                    {incentive.enabled && incentive.enabled_at
                        ? t('incentives.loyalty.enabled_since', {
                              date: new Date(
                                  incentive.enabled_at,
                              ).toLocaleDateString(
                                  i18n.language.replace('_', '-'),
                              ),
                          })
                        : t('incentives.loyalty.disabled_note')}
                </p>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat
                        icon={Gift}
                        label={t('incentives.stats.rewards_granted')}
                        value={String(stats.rewards_granted)}
                    />
                    <Stat
                        icon={Users}
                        label={t('incentives.stats.customers')}
                        value={String(stats.customers)}
                    />
                    <Stat
                        icon={Ticket}
                        label={t('incentives.stats.rewards_used')}
                        value={String(stats.rewards_used)}
                    />
                    <Stat
                        icon={Wallet}
                        label={t('incentives.stats.discount_given')}
                        value={formatBRL(stats.discount_given)}
                    />
                </div>
            </div>
        </article>
    );
}

export default function Incentives({
    incentives,
}: {
    incentives: IncentiveSetting[];
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('incentives.title')} />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
                <Heading
                    title={t('incentives.title')}
                    description={t('incentives.description')}
                />
                {incentives.map((incentive) => (
                    <LoyaltyIncentive
                        key={incentive.key}
                        incentive={incentive}
                    />
                ))}
            </div>
        </>
    );
}

Incentives.layout = {
    breadcrumbs: [
        {
            title: 'incentives.title',
            href: IncentiveController.index.url(),
        },
    ],
};
