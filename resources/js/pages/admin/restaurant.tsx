import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Ban,
    CheckCircle2,
    CircleDashed,
    ExternalLink,
    Mail,
    MapPin,
    Phone,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import RestaurantController from '@/actions/App/Http/Controllers/Admin/RestaurantController';
import AccountStatusControl from '@/components/admin/account-status-control';
import ActivityKpis from '@/components/admin/activity-kpis';
import DailyChart from '@/components/admin/daily-chart';
import Panel, { SectionTitle } from '@/components/admin/panel';
import RestaurantTermsFields from '@/components/admin/restaurant-terms-fields';
import { PlainStat } from '@/components/admin/window-stat';
import {
    formatDateTime,
    formatNumber,
    intlLocale,
} from '@/components/dashboard/format';
import PaymentShare from '@/components/dashboard/payment-share';
import RankedBars from '@/components/dashboard/ranked-bars';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatBRL } from '@/lib/money';
import type {
    AdminActivity,
    AdminDailyPoint,
    AdminPaymentMethods,
    FeeTierOption,
} from '@/types';

type Restaurant = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    handle: string | null;
    address: string | null;
    logo_url: string | null;
    profile_public: boolean;
    email_verified: boolean;
    two_factor: boolean;
    created_at: string | null;
    last_login_at: string | null;
    last_seen_at: string | null;
    stripe_connected: boolean;
    stripe_charges_enabled: boolean;
    stripe_details_submitted: boolean;
    mercadopago_connected: boolean;
    fee_tier: string;
    fee_percent_override: number | null;
    effective_fee_percent: number;
    platform_pays_stripe_fees: boolean;
    deactivated_at: string | null;
    deactivated_by: string | null;
    deactivation_reason: string | null;
};

type Props = {
    restaurant: Restaurant;
    tiers: FeeTierOption[];
    maxPercent: number;
    days: number;
    counts: {
        team: number;
        team_active: number;
        products: number;
        menus: number;
        queues: number;
        open_tables: number;
        last_table_opened_at: string | null;
    };
    activity: AdminActivity;
    timeline: AdminDailyPoint[];
    payment_methods: AdminPaymentMethods;
    top_products: {
        id: number;
        name: string;
        quantity: number;
        revenue: number;
    }[];
};

export default function AdminRestaurant({
    restaurant,
    tiers,
    maxPercent,
    days,
    counts,
    activity,
    timeline,
    payment_methods,
    top_products,
}: Props) {
    const { t, i18n } = useTranslation();
    const language = i18n.language;
    const locale = intlLocale(language);
    const num = (value: number) => formatNumber(value, language);
    const when = (iso: string | null) =>
        iso ? formatDateTime(iso, language) : t('admin_restaurant.never');

    return (
        <>
            <Head title={restaurant.name} />

            <div className="space-y-6 p-4 sm:p-6">
                <Button variant="ghost" size="sm" asChild className="-ml-2">
                    <Link href={RestaurantController.index.url()}>
                        <ArrowLeft className="size-4" />
                        {t('admin_restaurants.title')}
                    </Link>
                </Button>

                {/* Who they are */}
                <header className="flex flex-wrap items-start gap-4">
                    {restaurant.logo_url ? (
                        <img
                            src={restaurant.logo_url}
                            alt=""
                            className="size-16 rounded-xl border object-cover"
                        />
                    ) : (
                        <div className="flex size-16 items-center justify-center rounded-xl border bg-muted font-display text-2xl font-bold">
                            {restaurant.name.charAt(0).toUpperCase()}
                        </div>
                    )}
                    <div className="min-w-0 flex-1 space-y-1">
                        <h1 className="font-display text-2xl font-bold">
                            {restaurant.name}
                        </h1>
                        <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                            <a
                                href={`mailto:${restaurant.email}`}
                                className="inline-flex items-center gap-1 hover:text-foreground"
                            >
                                <Mail className="size-3.5" />
                                {restaurant.email}
                            </a>
                            {restaurant.phone && (
                                <a
                                    href={`tel:${restaurant.phone}`}
                                    className="inline-flex items-center gap-1 hover:text-foreground"
                                >
                                    <Phone className="size-3.5" />
                                    {restaurant.phone}
                                </a>
                            )}
                            {restaurant.address && (
                                <span className="inline-flex items-center gap-1">
                                    <MapPin className="size-3.5" />
                                    {restaurant.address}
                                </span>
                            )}
                            {restaurant.handle && restaurant.profile_public && (
                                <a
                                    href={`/vempro/${restaurant.handle}`}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center gap-1 hover:text-foreground"
                                >
                                    <ExternalLink className="size-3.5" />@
                                    {restaurant.handle}
                                </a>
                            )}
                        </div>
                        <div className="flex flex-wrap gap-2 pt-1">
                            <Badge
                                variant={
                                    restaurant.stripe_charges_enabled
                                        ? 'default'
                                        : 'secondary'
                                }
                            >
                                {restaurant.stripe_charges_enabled ? (
                                    <CheckCircle2 className="size-3" />
                                ) : (
                                    <CircleDashed className="size-3" />
                                )}
                                {restaurant.stripe_charges_enabled
                                    ? t('admin_restaurants.stripe_active')
                                    : restaurant.stripe_connected
                                      ? t('admin_restaurants.stripe_pending')
                                      : t('admin_restaurants.stripe_none')}
                            </Badge>
                            {restaurant.mercadopago_connected && (
                                <Badge variant="default">
                                    <CheckCircle2 className="size-3" />
                                    {t('admin_restaurants.mercadopago_active')}
                                </Badge>
                            )}
                            <Badge variant="outline">
                                {t('admin_restaurant.fee_badge', {
                                    percent:
                                        restaurant.effective_fee_percent.toLocaleString(
                                            locale,
                                        ),
                                })}
                            </Badge>
                            {!restaurant.email_verified && (
                                <Badge variant="destructive">
                                    {t('admin_restaurant.unverified')}
                                </Badge>
                            )}
                            {restaurant.two_factor && (
                                <Badge variant="secondary">
                                    {t('admin_restaurant.two_factor')}
                                </Badge>
                            )}
                        </div>
                    </div>
                    <AccountStatusControl
                        user={{ ...restaurant, account_type: 'restaurant' }}
                        deactivated={restaurant.deactivated_at !== null}
                        size="default"
                    />
                </header>

                {restaurant.deactivated_at && (
                    <div className="flex items-start gap-3 rounded-xl border border-destructive/40 bg-destructive/5 p-4 text-sm">
                        <Ban className="mt-0.5 size-4 shrink-0 text-destructive" />
                        <div className="space-y-1">
                            <p className="font-medium text-destructive">
                                {t('admin_users.deactivated_info', {
                                    when: formatDateTime(
                                        restaurant.deactivated_at,
                                        language,
                                    ),
                                    by:
                                        restaurant.deactivated_by ??
                                        t('admin_users.unknown_admin'),
                                })}
                            </p>
                            {restaurant.deactivation_reason && (
                                <p>{restaurant.deactivation_reason}</p>
                            )}
                            <p className="text-muted-foreground">
                                {t('admin_users.restaurant_deactivated_hint')}
                            </p>
                        </div>
                    </div>
                )}

                <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
                    <PlainStat
                        label={t('admin_restaurant.since')}
                        value={
                            restaurant.created_at
                                ? new Date(
                                      restaurant.created_at,
                                  ).toLocaleDateString(locale)
                                : '–'
                        }
                    />
                    <PlainStat
                        label={t('admin_restaurant.last_seen')}
                        value={when(restaurant.last_seen_at)}
                        hint={t('admin_restaurant.last_login', {
                            when: when(restaurant.last_login_at),
                        })}
                    />
                    <PlainStat
                        label={t('admin_restaurant.last_table')}
                        value={when(counts.last_table_opened_at)}
                    />
                    <PlainStat
                        label={t('admin_restaurant.open_tables')}
                        value={num(counts.open_tables)}
                    />
                    <PlainStat
                        label={t('admin_restaurant.team')}
                        value={num(counts.team)}
                        hint={t('admin_restaurant.team_active', {
                            count: counts.team_active,
                            days,
                        })}
                    />
                    <PlainStat
                        label={t('admin_restaurant.products')}
                        value={num(counts.products)}
                    />
                    <PlainStat
                        label={t('admin_restaurant.menus')}
                        value={num(counts.menus)}
                    />
                    <PlainStat
                        label={t('admin_restaurant.queues')}
                        value={num(counts.queues)}
                    />
                </div>

                <SectionTitle title={t('admin_overview.movement')} />
                <ActivityKpis activity={activity} days={days} />

                <DailyChart
                    points={timeline}
                    title={t('admin_overview.daily', { days })}
                />

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel title={t('admin_restaurant.top_products', { days })}>
                        {top_products.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('dashboards.no_data')}
                            </p>
                        ) : (
                            <RankedBars
                                rows={top_products.map((p) => ({
                                    key: p.id,
                                    label: p.name,
                                    value: p.revenue,
                                    display: formatBRL(p.revenue, locale),
                                    detail: `${num(p.quantity)}×`,
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

                {/* The platform's terms with them */}
                <SectionTitle
                    title={t('admin_restaurant.terms')}
                    description={t('admin_restaurant.terms_description')}
                />
                <Form
                    {...RestaurantController.update.form(restaurant.id)}
                    options={{ preserveScroll: true }}
                    className="max-w-2xl space-y-4 rounded-2xl border bg-card p-4 shadow-xs"
                >
                    {({ processing, errors }) => (
                        <>
                            <RestaurantTermsFields
                                key={`${restaurant.fee_tier}-${restaurant.fee_percent_override}-${restaurant.platform_pays_stripe_fees}`}
                                tiers={tiers}
                                maxPercent={maxPercent}
                                defaults={restaurant}
                                errors={errors}
                            />
                            <Button type="submit" disabled={processing}>
                                {t('admin_restaurant.save_terms')}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

AdminRestaurant.layout = {
    breadcrumbs: [
        {
            title: 'admin_restaurants.title',
            href: RestaurantController.index.url(),
        },
    ],
};
