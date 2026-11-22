import { Head, Link } from '@inertiajs/react';
import { Activity, LogIn, UserPlus } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import OverviewController from '@/actions/App/Http/Controllers/Admin/OverviewController';
import RestaurantController from '@/actions/App/Http/Controllers/Admin/RestaurantController';
import ActivityKpis from '@/components/admin/activity-kpis';
import DailyChart from '@/components/admin/daily-chart';
import Panel, { SectionTitle } from '@/components/admin/panel';
import WindowStat, { PlainStat } from '@/components/admin/window-stat';
import type { Windows } from '@/components/admin/window-stat';
import {
    formatNumber,
    formatPercent,
    intlLocale,
} from '@/components/dashboard/format';
import PaymentShare from '@/components/dashboard/payment-share';
import RankedBars from '@/components/dashboard/ranked-bars';
import Heading from '@/components/heading';
import { formatBRL } from '@/lib/money';
import type {
    AdminActivity,
    AdminDailyPoint,
    AdminPaymentMethods,
} from '@/types';

type Props = {
    users: {
        total: number;
        verified: number;
        by_type: Record<'customer' | 'restaurant' | 'waiter' | 'admin', number>;
        signups: Windows;
        logins: Windows;
        active: Windows;
        customers_active_week: number;
        customers_seated_month: number;
    };
    restaurants: {
        total: number;
        payments_active: number;
        public_profiles: number;
        custom_fee: number;
        new_month: number;
        operating_week: number;
        operating_month: number;
    };
    activity: AdminActivity;
    timeline: AdminDailyPoint[];
    payment_methods: AdminPaymentMethods;
    top_restaurants: {
        id: number;
        name: string;
        revenue: number;
        tables: number;
    }[];
    days: number;
};

const ICON = 'size-4';

export default function AdminOverview({
    users,
    restaurants,
    activity,
    timeline,
    payment_methods,
    top_restaurants,
    days,
}: Props) {
    const { t, i18n } = useTranslation();
    const language = i18n.language;
    const num = (value: number) => formatNumber(value, language);
    const share = (part: number, whole: number) =>
        whole > 0 ? formatPercent(part / whole, language) : '–';

    return (
        <>
            <Head title={t('admin_overview.title')} />

            <div className="space-y-6 p-4 sm:p-6">
                <Heading
                    title={t('admin_overview.title')}
                    description={t('admin_overview.description')}
                />

                {/* People: counted, never listed. */}
                <SectionTitle
                    title={t('admin_overview.people')}
                    description={t('admin_overview.people_description')}
                />
                <div className="grid gap-4 md:grid-cols-3">
                    <WindowStat
                        icon={<Activity className={ICON} />}
                        label={t('admin_overview.active')}
                        windows={users.active}
                        hint={t('admin_overview.active_hint')}
                    />
                    <WindowStat
                        icon={<LogIn className={ICON} />}
                        label={t('admin_overview.logins')}
                        windows={users.logins}
                        hint={t('admin_overview.logins_hint')}
                    />
                    <WindowStat
                        icon={<UserPlus className={ICON} />}
                        label={t('admin_overview.signups')}
                        windows={users.signups}
                    />
                </div>
                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <PlainStat
                        label={t('admin_overview.accounts')}
                        value={num(users.total)}
                        hint={t('admin_overview.verified_share', {
                            share: share(users.verified, users.total),
                        })}
                    />
                    <PlainStat
                        label={t('admin_overview.customers')}
                        value={num(users.by_type.customer)}
                        hint={t('admin_overview.customers_active_week', {
                            count: users.customers_active_week,
                        })}
                    />
                    <PlainStat
                        label={t('admin_overview.seated')}
                        value={num(users.customers_seated_month)}
                        hint={t('admin_overview.last_days', { days })}
                    />
                    <PlainStat
                        label={t('admin_overview.restaurant_accounts')}
                        value={num(users.by_type.restaurant)}
                    />
                    <PlainStat
                        label={t('admin_overview.staff')}
                        value={num(users.by_type.waiter)}
                    />
                    <PlainStat
                        label={t('admin_overview.admins')}
                        value={num(users.by_type.admin)}
                    />
                </div>

                <SectionTitle
                    title={t('admin_overview.restaurants')}
                    description={t('admin_overview.restaurants_description')}
                />
                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <PlainStat
                        label={t('admin_overview.restaurants_total')}
                        value={num(restaurants.total)}
                        hint={t('admin_overview.new_in_days', {
                            count: restaurants.new_month,
                            days,
                        })}
                    />
                    <PlainStat
                        label={t('admin_overview.operating_week')}
                        value={num(restaurants.operating_week)}
                        hint={share(
                            restaurants.operating_week,
                            restaurants.total,
                        )}
                    />
                    <PlainStat
                        label={t('admin_overview.operating_month', { days })}
                        value={num(restaurants.operating_month)}
                        hint={share(
                            restaurants.operating_month,
                            restaurants.total,
                        )}
                    />
                    <PlainStat
                        label={t('admin_overview.payments_active')}
                        value={num(restaurants.payments_active)}
                        hint={share(
                            restaurants.payments_active,
                            restaurants.total,
                        )}
                    />
                    <PlainStat
                        label={t('admin_overview.public_profiles')}
                        value={num(restaurants.public_profiles)}
                    />
                    <PlainStat
                        label={t('admin_overview.custom_fee')}
                        value={num(restaurants.custom_fee)}
                    />
                </div>

                <SectionTitle
                    title={t('admin_overview.movement')}
                    description={t('admin_overview.movement_description')}
                />
                <ActivityKpis activity={activity} days={days} />

                <DailyChart
                    points={timeline}
                    title={t('admin_overview.daily', { days })}
                />

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel
                        title={t('admin_overview.top_restaurants', { days })}
                    >
                        {top_restaurants.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('dashboards.no_data')}
                            </p>
                        ) : (
                            <RankedBars
                                rows={top_restaurants.map((r) => ({
                                    key: r.id,
                                    label: r.name,
                                    value: r.revenue,
                                    display: formatBRL(
                                        r.revenue,
                                        intlLocale(language),
                                    ),
                                    detail: (
                                        <Link
                                            href={RestaurantController.show.url(
                                                r.id,
                                            )}
                                            className="underline-offset-2 hover:underline"
                                        >
                                            {t('admin_overview.tables_count', {
                                                count: r.tables,
                                            })}
                                        </Link>
                                    ),
                                }))}
                            />
                        )}
                    </Panel>
                    <Panel
                        title={t('admin_overview.payment_methods', { days })}
                    >
                        <PaymentShare methods={payment_methods} />
                    </Panel>
                </div>
            </div>
        </>
    );
}

AdminOverview.layout = {
    breadcrumbs: [
        { title: 'admin_overview.title', href: OverviewController.index.url() },
    ],
};
