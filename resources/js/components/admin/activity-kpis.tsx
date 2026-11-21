import {
    Banknote,
    HandCoins,
    ReceiptText,
    ShoppingBag,
    Smartphone,
    UtensilsCrossed,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { PlainStat } from '@/components/admin/window-stat';
import { formatNumber, intlLocale } from '@/components/dashboard/format';
import KpiCard from '@/components/dashboard/kpi-card';
import { formatBRL } from '@/lib/money';
import type { AdminActivity } from '@/types';

const ICON = 'size-4';

/**
 * Last 30 days against the 30 before, then the all-time totals: what was
 * paid, bills closed, items ordered, average ticket and the platform's
 * commission on in-app payments.
 */
export default function ActivityKpis({
    activity,
    days,
}: {
    activity: AdminActivity;
    days: number;
}) {
    const { t, i18n } = useTranslation();
    const language = i18n.language;
    const brl = (value: number) => formatBRL(value, intlLocale(language));
    const num = (value: number) => formatNumber(value, language);
    const hint = t('admin_overview.vs_previous_days', { days });

    return (
        <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <KpiCard
                    icon={<Banknote className={ICON} />}
                    label={t('admin_overview.kpi_paid', { days })}
                    value={brl(activity.payments.month)}
                    current={activity.payments.month}
                    previous={activity.payments.previous}
                    hint={hint}
                />
                <KpiCard
                    icon={<UtensilsCrossed className={ICON} />}
                    label={t('admin_overview.kpi_tables', { days })}
                    value={num(activity.tables.month)}
                    current={activity.tables.month}
                    previous={activity.tables.previous}
                    hint={hint}
                />
                <KpiCard
                    icon={<ShoppingBag className={ICON} />}
                    label={t('admin_overview.kpi_items', { days })}
                    value={num(activity.items.month)}
                    current={activity.items.month}
                    previous={activity.items.previous}
                    hint={hint}
                />
                <KpiCard
                    icon={<ReceiptText className={ICON} />}
                    label={t('admin_overview.kpi_ticket', { days })}
                    value={brl(activity.payments.avg_ticket_month)}
                    current={activity.payments.avg_ticket_month}
                    previous={activity.payments.avg_ticket_previous}
                    neutral
                    hint={hint}
                />
                <KpiCard
                    icon={<ReceiptText className={ICON} />}
                    label={t('admin_overview.kpi_payments', { days })}
                    value={num(activity.payments.count_month)}
                    current={activity.payments.count_month}
                    previous={activity.payments.count_previous}
                    hint={hint}
                />
                <KpiCard
                    icon={<HandCoins className={ICON} />}
                    label={t('admin_overview.kpi_commission', { days })}
                    value={brl(activity.online.fee_month)}
                    current={activity.online.fee_month}
                    previous={activity.online.fee_previous}
                    hint={hint}
                />
            </div>

            <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <PlainStat
                    label={t('admin_overview.today_paid')}
                    value={brl(activity.payments.day)}
                    hint={t('admin_overview.today_hint', {
                        tables: activity.tables.day,
                        items: activity.items.day,
                    })}
                />
                <PlainStat
                    label={t('admin_overview.all_paid')}
                    value={brl(activity.payments.total)}
                    hint={t('admin_overview.all_payments', {
                        count: activity.payments.count_total,
                    })}
                />
                <PlainStat
                    label={t('admin_overview.all_tables')}
                    value={num(activity.tables.total)}
                />
                <PlainStat
                    label={t('admin_overview.all_items')}
                    value={num(activity.items.total)}
                    hint={t('admin_overview.abandoned', {
                        count: activity.items.abandoned_month,
                        days,
                    })}
                />
                <PlainStat
                    label={t('admin_overview.online_paid')}
                    value={brl(activity.online.total)}
                    hint={t('admin_overview.all_payments', {
                        count: activity.online.count_total,
                    })}
                />
                <PlainStat
                    label={t('admin_overview.all_commission')}
                    value={brl(activity.online.fee_total)}
                    hint={
                        activity.online.platform_coupons_total > 0
                            ? t('admin_overview.platform_coupons', {
                                  amount: brl(
                                      activity.online.platform_coupons_total,
                                  ),
                              })
                            : undefined
                    }
                />
            </div>
            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Smartphone className="size-3.5" aria-hidden />
                {t('admin_overview.paid_note')}
            </p>
        </div>
    );
}
