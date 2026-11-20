import { Check, Copy, Ticket } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { useClipboard } from '@/hooks/use-clipboard';
import { formatBRL } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { WalletCoupon as Coupon } from '@/types';

/** A reward coupon drawn as a ticket stub, with its code one tap from the clipboard. */
export default function WalletCoupon({ coupon }: { coupon: Coupon }) {
    const { t, i18n } = useTranslation();
    const [copied, copy] = useClipboard();
    const available = coupon.status === 'available';
    const locale = i18n.language.replace('_', '-');

    return (
        <div
            className={cn(
                'relative flex overflow-hidden rounded-2xl border shadow-xs',
                available
                    ? 'border-primary/40 bg-gradient-to-br from-primary/15 via-card to-card'
                    : 'bg-card opacity-60 grayscale',
            )}
        >
            <div className="flex w-24 shrink-0 flex-col items-center justify-center gap-1 border-r-2 border-dashed border-border p-3">
                <Ticket className="size-5 text-primary" />
                <span className="font-display text-xl leading-none font-extrabold tabular-nums">
                    {coupon.discount_type === 'percent'
                        ? `${coupon.value.toLocaleString(locale)}%`
                        : formatBRL(coupon.value, locale)}
                </span>
                <span className="text-[10px] text-muted-foreground uppercase">
                    {coupon.discount_type === 'percent' &&
                    coupon.max_discount !== null
                        ? t('customer_rewards.wallet.up_to', {
                              value: formatBRL(coupon.max_discount, locale),
                          })
                        : t('customer_rewards.wallet.off')}
                </span>
            </div>
            <div className="min-w-0 flex-1 space-y-1.5 p-3">
                <div className="flex items-center gap-2">
                    <span className="truncate text-sm font-semibold">
                        {coupon.restaurant.name}
                    </span>
                    {!available && (
                        <Badge variant="outline" className="text-[10px]">
                            {t(`customer_rewards.wallet.${coupon.status}`)}
                        </Badge>
                    )}
                </div>
                <button
                    type="button"
                    disabled={!available}
                    onClick={() => copy(coupon.code)}
                    className="inline-flex max-w-full items-center gap-1.5 rounded-md border border-dashed bg-background px-2 py-1 font-mono text-xs font-bold tracking-wide enabled:hover:border-primary"
                    aria-label={t('customer_rewards.wallet.copy', {
                        code: coupon.code,
                    })}
                >
                    <span className="truncate">{coupon.code}</span>
                    {copied === coupon.code ? (
                        <Check className="size-3.5 text-primary" />
                    ) : (
                        <Copy className="size-3.5 text-muted-foreground" />
                    )}
                </button>
                {coupon.expires_at && (
                    <p className="text-xs text-muted-foreground">
                        {t('customer_rewards.wallet.valid_until', {
                            date: new Date(
                                coupon.expires_at,
                            ).toLocaleDateString(
                                i18n.language.replace('_', '-'),
                            ),
                        })}
                    </p>
                )}
            </div>
        </div>
    );
}
