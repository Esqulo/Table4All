import { Link } from '@inertiajs/react';
import { Flame, Hourglass, Lock, PartyPopper } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import RestaurantProfileController from '@/actions/App/Http/Controllers/RestaurantProfileController';
import { formatDiscount } from '@/lib/discount';
import { cn } from '@/lib/utils';
import type { LoyaltyCard as Card } from '@/types';
import SegmentedProgress from './segmented-progress';

/** A day count this close to the end makes the card warn about the streak. */
const URGENT_DAYS = 7;

export default function LoyaltyCard({ card }: { card: Card }) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language.replace('_', '-');
    const { restaurant, tiers } = card;
    const urgent = card.days_left <= URGENT_DAYS;
    const filled = card.progress;
    const describe = (tier: (typeof tiers)[number]) =>
        formatDiscount(tier, t, locale);
    // The tier the last visit reached, if any.
    const earned = card.just_earned
        ? tiers.find((tier) => tier.visits === filled)
        : undefined;
    // Next tier on the ladder; after the top it starts over from the first.
    const atTop = filled >= card.segments;
    const next = atTop
        ? tiers[0]
        : (tiers.find((tier) => tier.visits > filled) ?? tiers[0]);
    const missing = atTop ? next.visits : next.visits - filled;

    const name = (
        <span className="truncate font-semibold">{restaurant.name}</span>
    );

    return (
        <article className="relative flex flex-col gap-4 overflow-hidden rounded-2xl border bg-card p-4 shadow-xs">
            <div
                className="pointer-events-none absolute -top-16 -right-16 size-40 rounded-full bg-primary/10 blur-2xl"
                aria-hidden
            />

            <header className="flex items-center gap-3">
                {restaurant.logo_url ? (
                    <img
                        src={restaurant.logo_url}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        className="size-11 shrink-0 rounded-xl object-cover"
                    />
                ) : (
                    <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/15 font-display text-lg font-extrabold text-primary">
                        {restaurant.name.charAt(0).toUpperCase()}
                    </span>
                )}
                <div className="min-w-0 flex-1">
                    {restaurant.handle ? (
                        <Link
                            href={RestaurantProfileController.show.url(
                                restaurant.handle,
                            )}
                            className="block truncate hover:underline"
                        >
                            {name}
                        </Link>
                    ) : (
                        <div className="truncate">{name}</div>
                    )}
                    <p className="flex items-center gap-1 text-xs text-muted-foreground">
                        <Flame className="size-3.5 text-orange-500" />
                        {t('customer_rewards.loyalty.streak', {
                            count: card.streak,
                        })}
                    </p>
                </div>
                <span
                    className="flex flex-col items-center rounded-xl bg-orange-500/10 px-2.5 py-1 text-orange-600 dark:text-orange-400"
                    title={t('customer_rewards.loyalty.streak', {
                        count: card.streak,
                    })}
                >
                    <Flame className="size-4" />
                    <span className="font-display text-lg leading-none font-extrabold tabular-nums">
                        {card.streak}
                    </span>
                </span>
            </header>

            {card.rewards_enabled ? (
                <div className="space-y-2">
                    <SegmentedProgress
                        segments={card.segments}
                        filled={filled}
                        checkpoints={tiers.map((tier) => tier.visits)}
                        label={t('customer_rewards.loyalty.progress_label', {
                            filled,
                            total: card.segments,
                        })}
                    />
                    {earned && (
                        <p className="flex items-center gap-1.5 text-sm font-medium">
                            <PartyPopper className="size-4 shrink-0 text-primary" />
                            {t('customer_rewards.loyalty.earned', {
                                value: describe(earned),
                            })}
                        </p>
                    )}
                    <p className="text-sm">
                        {t('customer_rewards.loyalty.missing', {
                            count: missing,
                            value: describe(next),
                        })}
                    </p>
                    <TierLadder
                        tiers={tiers}
                        filled={filled}
                        describe={describe}
                    />
                </div>
            ) : (
                <div className="space-y-2">
                    <SegmentedProgress
                        segments={card.segments}
                        filled={0}
                        checkpoints={tiers.map((tier) => tier.visits)}
                        label={t('customer_rewards.loyalty.no_rewards')}
                        disabled
                    />
                    <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                        <Lock className="size-3.5" />
                        {t('customer_rewards.loyalty.no_rewards')}
                    </p>
                </div>
            )}

            <p
                className={cn(
                    'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs',
                    urgent
                        ? 'bg-destructive/10 font-medium text-destructive'
                        : 'bg-muted text-muted-foreground',
                )}
            >
                <Hourglass className="size-3.5 shrink-0" />
                {card.days_left === 0
                    ? t('customer_rewards.loyalty.last_day')
                    : t('customer_rewards.loyalty.days_left', {
                          count: card.days_left,
                      })}
            </p>
        </article>
    );
}

/** The ladder's tiers as chips: what each one gives, lit once reached. */
function TierLadder({
    tiers,
    filled,
    describe,
}: {
    tiers: Card['tiers'];
    filled: number;
    describe: (tier: Card['tiers'][number]) => string;
}) {
    const { t } = useTranslation();

    if (tiers.length < 2) {
        return null;
    }

    return (
        <ol className="flex flex-wrap gap-1.5">
            {tiers.map((tier) => (
                <li
                    key={tier.visits}
                    className={cn(
                        'rounded-full border px-2 py-0.5 text-[11px]',
                        filled >= tier.visits
                            ? 'border-primary/50 bg-primary/10 font-medium text-foreground'
                            : 'text-muted-foreground',
                    )}
                >
                    {t('customer_rewards.loyalty.tier', {
                        count: tier.visits,
                        value: describe(tier),
                    })}
                </li>
            ))}
        </ol>
    );
}
