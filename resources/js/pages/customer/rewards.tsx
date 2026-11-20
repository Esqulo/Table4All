import { Head, Link } from '@inertiajs/react';
import {
    Award,
    ChevronLeft,
    ChevronRight,
    Compass,
    Crown,
    Flame,
    MapPin,
    PiggyBank,
    QrCode,
    Receipt,
    Sparkles,
    Star,
    Ticket,
    Trophy,
    Utensils,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import CustomerJoinController from '@/actions/App/Http/Controllers/Customer/JoinController';
import CustomerRewardController from '@/actions/App/Http/Controllers/Customer/RewardController';
import LoyaltyCard from '@/components/loyalty/loyalty-card';
import WalletCoupon from '@/components/loyalty/wallet-coupon';
import { Button } from '@/components/ui/button';
import { formatBRL } from '@/lib/money';
import { cn } from '@/lib/utils';
import type {
    Achievement,
    AchievementKey,
    CustomerHistory,
    CustomerLevel,
    CustomerStats,
    LoyaltyCard as LoyaltyCardData,
    LoyaltyRules,
    WalletCoupon as WalletCouponData,
} from '@/types';

type Props = {
    level: CustomerLevel;
    stats: CustomerStats;
    achievements: Achievement[];
    loyalty: LoyaltyCardData[];
    loyalty_rules: LoyaltyRules;
    wallet: WalletCouponData[];
    history: CustomerHistory;
};

const ACHIEVEMENT_ICON: Record<AchievementKey, LucideIcon> = {
    first_visit: Utensils,
    regular: Star,
    explorer: Compass,
    loyal: Flame,
    saver: PiggyBank,
    big_spender: Crown,
};

function Section({
    icon: Icon,
    title,
    description,
    children,
}: {
    icon: LucideIcon;
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <section className="space-y-3">
            <div>
                <h2 className="flex items-center gap-2 font-display text-xl font-extrabold">
                    <Icon className="size-5 text-primary" />
                    {title}
                </h2>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {children}
        </section>
    );
}

function StatChip({
    icon: Icon,
    label,
    value,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
}) {
    return (
        <div className="flex items-center gap-3 rounded-2xl border bg-card p-3 shadow-xs">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                <Icon className="size-[18px]" />
            </span>
            <div className="min-w-0">
                <p className="truncate text-xs text-muted-foreground">
                    {label}
                </p>
                <p className="truncate text-base font-bold tabular-nums">
                    {value}
                </p>
            </div>
        </div>
    );
}

export default function CustomerRewards({
    level,
    stats,
    achievements,
    loyalty,
    loyalty_rules: rules,
    wallet,
    history,
}: Props) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language.replace('_', '-');
    const span = level.next_level_xp - level.level_xp;
    const levelProgress = span > 0 ? (level.xp - level.level_xp) / span : 0;
    const available = wallet.filter((c) => c.status === 'available');
    const unlocked = achievements.filter((a) => a.unlocked).length;
    const date = (iso: string) =>
        new Date(iso).toLocaleDateString(locale, {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        });

    return (
        <>
            <Head title={t('customer_rewards.title')} />

            <div className="mx-auto w-full max-w-5xl space-y-8 p-4 sm:p-6">
                {/* Level hero */}
                <section className="relative overflow-hidden rounded-3xl border bg-gradient-to-br from-primary/25 via-card to-card p-5 shadow-sm sm:p-6">
                    <Sparkles
                        className="pointer-events-none absolute top-4 right-4 size-24 text-primary/10"
                        aria-hidden
                    />
                    <div className="flex items-center gap-4">
                        <div className="relative flex size-20 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-[0_0_32px_-6px_var(--primary)]">
                            <div className="text-center leading-none">
                                <span className="block text-[10px] font-semibold tracking-widest uppercase opacity-80">
                                    {t('customer_rewards.level.short')}
                                </span>
                                <span className="font-display text-3xl font-extrabold tabular-nums">
                                    {level.level}
                                </span>
                            </div>
                        </div>
                        <div className="min-w-0 flex-1 space-y-2">
                            <h1 className="font-display text-2xl leading-tight font-extrabold sm:text-3xl">
                                {t('customer_rewards.title')}
                            </h1>
                            <div
                                className="h-2.5 overflow-hidden rounded-full bg-background/70"
                                role="progressbar"
                                aria-label={t(
                                    'customer_rewards.level.progress',
                                )}
                                aria-valuemin={level.level_xp}
                                aria-valuemax={level.next_level_xp}
                                aria-valuenow={level.xp}
                            >
                                <div
                                    className="h-full rounded-full bg-primary transition-[width] duration-700"
                                    style={{
                                        width: `${Math.max(3, levelProgress * 100)}%`,
                                    }}
                                />
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {t('customer_rewards.level.to_next', {
                                    xp: level.xp,
                                    next: level.next_level_xp,
                                    missing: level.next_level_xp - level.xp,
                                    level: level.level + 1,
                                })}
                            </p>
                        </div>
                    </div>
                    <p className="mt-4 rounded-xl bg-background/60 px-3 py-2 text-xs text-muted-foreground">
                        {t('customer_rewards.level.how')}
                    </p>
                </section>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <StatChip
                        icon={Utensils}
                        label={t('customer_rewards.stats.visits')}
                        value={String(stats.visits)}
                    />
                    <StatChip
                        icon={MapPin}
                        label={t('customer_rewards.stats.places')}
                        value={String(stats.places)}
                    />
                    <StatChip
                        icon={Flame}
                        label={t('customer_rewards.stats.best_streak')}
                        value={String(stats.best_streak)}
                    />
                    <StatChip
                        icon={PiggyBank}
                        label={t('customer_rewards.stats.saved')}
                        value={formatBRL(stats.saved)}
                    />
                </div>

                <Section
                    icon={Flame}
                    title={t('customer_rewards.loyalty.title')}
                    description={t('customer_rewards.loyalty.description', {
                        days: rules.streak_days,
                    })}
                >
                    {loyalty.length === 0 ? (
                        <div className="flex flex-col items-center gap-3 rounded-2xl border border-dashed p-8 text-center">
                            <Flame className="size-8 text-muted-foreground" />
                            <p className="max-w-sm text-sm text-muted-foreground">
                                {t('customer_rewards.loyalty.empty')}
                            </p>
                            <Button asChild>
                                <Link href={CustomerJoinController.index.url()}>
                                    <QrCode className="size-4" />
                                    {t('customer_rewards.join_table')}
                                </Link>
                            </Button>
                        </div>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {loyalty.map((card) => (
                                <LoyaltyCard
                                    key={card.restaurant.id}
                                    card={card}
                                />
                            ))}
                        </div>
                    )}
                </Section>

                <Section
                    icon={Wallet}
                    title={t('customer_rewards.wallet.title')}
                    description={
                        available.length > 0
                            ? t('customer_rewards.wallet.available', {
                                  count: available.length,
                              })
                            : t('customer_rewards.wallet.description')
                    }
                >
                    {wallet.length === 0 ? (
                        <p className="flex items-center gap-2 rounded-2xl border border-dashed p-6 text-sm text-muted-foreground">
                            <Ticket className="size-5 shrink-0" />
                            {t('customer_rewards.wallet.empty')}
                        </p>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2">
                            {wallet.map((coupon) => (
                                <WalletCoupon key={coupon.id} coupon={coupon} />
                            ))}
                        </div>
                    )}
                </Section>

                <Section
                    icon={Trophy}
                    title={t('customer_rewards.achievements.title')}
                    description={t('customer_rewards.achievements.count', {
                        unlocked,
                        total: achievements.length,
                    })}
                >
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        {achievements.map((a) => {
                            const Icon = ACHIEVEMENT_ICON[a.key];

                            return (
                                <div
                                    key={a.key}
                                    className={cn(
                                        'flex flex-col gap-2 rounded-2xl border p-3 shadow-xs',
                                        a.unlocked
                                            ? 'border-primary/40 bg-primary/5'
                                            : 'bg-card',
                                    )}
                                >
                                    <div className="flex items-center gap-2">
                                        <span
                                            className={cn(
                                                'flex size-9 shrink-0 items-center justify-center rounded-full',
                                                a.unlocked
                                                    ? 'bg-primary text-primary-foreground'
                                                    : 'bg-muted text-muted-foreground',
                                            )}
                                        >
                                            <Icon className="size-4" />
                                        </span>
                                        <span className="text-sm leading-tight font-semibold">
                                            {t(
                                                `customer_rewards.achievements.${a.key}.title`,
                                            )}
                                        </span>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {t(
                                            `customer_rewards.achievements.${a.key}.description`,
                                            { count: a.goal },
                                        )}
                                    </p>
                                    {a.unlocked ? (
                                        <p className="flex items-center gap-1 text-xs font-medium text-primary">
                                            <Award className="size-3.5" />
                                            {t(
                                                'customer_rewards.achievements.unlocked',
                                            )}
                                        </p>
                                    ) : (
                                        <div className="flex items-center gap-2">
                                            <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full bg-primary/60"
                                                    style={{
                                                        width: `${(a.progress / a.goal) * 100}%`,
                                                    }}
                                                />
                                            </div>
                                            <span className="text-[10px] text-muted-foreground tabular-nums">
                                                {a.progress}/{a.goal}
                                            </span>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </Section>

                <Section
                    icon={Receipt}
                    title={t('customer_rewards.history.title')}
                    description={t('customer_rewards.history.description', {
                        value: formatBRL(stats.saved),
                    })}
                >
                    {history.data.length === 0 ? (
                        <p className="rounded-2xl border border-dashed p-6 text-sm text-muted-foreground">
                            {t('customer_rewards.history.empty')}
                        </p>
                    ) : (
                        <>
                            <ul className="divide-y rounded-2xl border bg-card shadow-xs">
                                {history.data.map((table) => (
                                    <li
                                        key={table.id}
                                        className="space-y-2 p-4"
                                    >
                                        <div className="flex flex-wrap items-start gap-x-3 gap-y-1">
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate font-semibold">
                                                    {table.restaurant.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {table.title} ·{' '}
                                                    {date(table.joined_at)}
                                                </p>
                                            </div>
                                            <div className="text-right">
                                                <p className="font-semibold tabular-nums">
                                                    {formatBRL(table.my_total)}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {t(
                                                        'customer_rewards.history.table_total',
                                                        {
                                                            value: formatBRL(
                                                                table.table_total,
                                                            ),
                                                        },
                                                    )}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            {table.code ? (
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-7 rounded-full text-xs"
                                                >
                                                    <Link
                                                        href={CustomerJoinController.show.url(
                                                            table.code,
                                                        )}
                                                    >
                                                        {t(
                                                            'customer_rewards.history.open',
                                                        )}
                                                    </Link>
                                                </Button>
                                            ) : null}
                                            {table.payments.map((p, i) => (
                                                <span
                                                    key={i}
                                                    className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs"
                                                >
                                                    {t(
                                                        `customer_rewards.history.payment_${p.status}`,
                                                        {
                                                            value: formatBRL(
                                                                p.charged,
                                                            ),
                                                        },
                                                    )}
                                                    {p.discount > 0 && (
                                                        <span className="font-medium text-primary">
                                                            {t(
                                                                'customer_rewards.history.saved',
                                                                {
                                                                    value: formatBRL(
                                                                        p.discount,
                                                                    ),
                                                                },
                                                            )}
                                                        </span>
                                                    )}
                                                </span>
                                            ))}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                            {history.last_page > 1 && (
                                <div className="flex items-center justify-between gap-2">
                                    <Button
                                        asChild={history.prev_page_url !== null}
                                        variant="outline"
                                        size="sm"
                                        disabled={
                                            history.prev_page_url === null
                                        }
                                    >
                                        {history.prev_page_url ? (
                                            <Link
                                                href={history.prev_page_url}
                                                preserveScroll
                                                only={['history']}
                                            >
                                                <ChevronLeft className="size-4" />
                                                {t(
                                                    'customer_rewards.history.prev',
                                                )}
                                            </Link>
                                        ) : (
                                            <span>
                                                <ChevronLeft className="size-4" />
                                                {t(
                                                    'customer_rewards.history.prev',
                                                )}
                                            </span>
                                        )}
                                    </Button>
                                    <span className="text-xs text-muted-foreground">
                                        {t('customer_rewards.history.page', {
                                            page: history.current_page,
                                            total: history.last_page,
                                        })}
                                    </span>
                                    <Button
                                        asChild={history.next_page_url !== null}
                                        variant="outline"
                                        size="sm"
                                        disabled={
                                            history.next_page_url === null
                                        }
                                    >
                                        {history.next_page_url ? (
                                            <Link
                                                href={history.next_page_url}
                                                preserveScroll
                                                only={['history']}
                                            >
                                                {t(
                                                    'customer_rewards.history.next',
                                                )}
                                                <ChevronRight className="size-4" />
                                            </Link>
                                        ) : (
                                            <span>
                                                {t(
                                                    'customer_rewards.history.next',
                                                )}
                                                <ChevronRight className="size-4" />
                                            </span>
                                        )}
                                    </Button>
                                </div>
                            )}
                        </>
                    )}
                </Section>
            </div>
        </>
    );
}

CustomerRewards.layout = {
    breadcrumbs: [
        {
            title: 'customer_rewards.title',
            href: CustomerRewardController.index.url(),
        },
    ],
};
