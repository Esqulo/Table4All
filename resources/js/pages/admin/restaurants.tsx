import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, CircleDashed, Search } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import RestaurantController from '@/actions/App/Http/Controllers/Admin/RestaurantController';
import Heading from '@/components/heading';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

type RestaurantRow = {
    id: number;
    name: string;
    email: string;
    fee_tier: string;
    platform_pays_stripe_fees: boolean;
    stripe_connected: boolean;
    stripe_charges_enabled: boolean;
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    last_page: number;
};

type Props = {
    restaurants: Paginated<RestaurantRow>;
    tiers: { key: string; fee_percent: number }[];
    search: string;
};

export default function AdminRestaurants({
    restaurants,
    tiers,
    search,
}: Props) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language === 'pt_BR' ? 'pt-BR' : 'en-US';
    const [query, setQuery] = useState(search);
    const [saving, setSaving] = useState<number | null>(null);

    function save(
        restaurant: RestaurantRow,
        changes: Partial<
            Pick<RestaurantRow, 'fee_tier' | 'platform_pays_stripe_fees'>
        >,
    ) {
        setSaving(restaurant.id);
        router.patch(
            RestaurantController.update.url(restaurant.id),
            {
                fee_tier: changes.fee_tier ?? restaurant.fee_tier,
                platform_pays_stripe_fees:
                    changes.platform_pays_stripe_fees ??
                    restaurant.platform_pays_stripe_fees,
            },
            { preserveScroll: true, onFinish: () => setSaving(null) },
        );
    }

    const tierLabel = (key: string) => {
        const tier = tiers.find((x) => x.key === key);
        const name = t(`payments.tiers.${key}`, { defaultValue: key });

        return tier
            ? `${name} · ${tier.fee_percent.toLocaleString(locale)}%`
            : name;
    };

    return (
        <>
            <Head title={t('admin_restaurants.title')} />

            <div className="space-y-6 p-6">
                <Heading
                    title={t('admin_restaurants.title')}
                    description={t('admin_restaurants.description')}
                />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(
                            RestaurantController.index.url(),
                            query.trim() === '' ? {} : { q: query.trim() },
                            { preserveState: true },
                        );
                    }}
                    className="relative max-w-sm"
                >
                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder={t('admin_restaurants.search')}
                        className="pl-9"
                    />
                </form>

                {restaurants.data.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-12 text-center text-sm text-muted-foreground">
                        {t('admin_restaurants.empty')}
                    </p>
                ) : (
                    <ul className="divide-y divide-border rounded-xl border border-border">
                        {restaurants.data.map((restaurant) => (
                            <li
                                key={restaurant.id}
                                className={cn(
                                    'flex flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3',
                                    saving === restaurant.id && 'opacity-60',
                                )}
                            >
                                <div className="min-w-48 flex-1">
                                    <p className="font-medium">
                                        {restaurant.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {restaurant.email}
                                    </p>
                                    <p className="mt-1 flex items-center gap-1 text-xs">
                                        {restaurant.stripe_charges_enabled ? (
                                            <>
                                                <CheckCircle2 className="h-3.5 w-3.5 text-green-600" />
                                                {t(
                                                    'admin_restaurants.stripe_active',
                                                )}
                                            </>
                                        ) : (
                                            <>
                                                <CircleDashed className="h-3.5 w-3.5 text-muted-foreground" />
                                                <span className="text-muted-foreground">
                                                    {restaurant.stripe_connected
                                                        ? t(
                                                              'admin_restaurants.stripe_pending',
                                                          )
                                                        : t(
                                                              'admin_restaurants.stripe_none',
                                                          )}
                                                </span>
                                            </>
                                        )}
                                    </p>
                                </div>

                                <div className="w-48">
                                    <Select
                                        value={restaurant.fee_tier}
                                        disabled={saving !== null}
                                        onValueChange={(fee_tier) =>
                                            save(restaurant, { fee_tier })
                                        }
                                    >
                                        <SelectTrigger
                                            aria-label={t(
                                                'admin_restaurants.tier',
                                            )}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {tiers.map((tier) => (
                                                <SelectItem
                                                    key={tier.key}
                                                    value={tier.key}
                                                >
                                                    {tierLabel(tier.key)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <label className="flex cursor-pointer items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={
                                            restaurant.platform_pays_stripe_fees
                                        }
                                        disabled={saving !== null}
                                        onCheckedChange={(checked) =>
                                            save(restaurant, {
                                                platform_pays_stripe_fees:
                                                    checked === true,
                                            })
                                        }
                                    />
                                    {t('admin_restaurants.platform_pays_fees')}
                                </label>
                            </li>
                        ))}
                    </ul>
                )}

                {restaurants.last_page > 1 && (
                    <nav className="flex flex-wrap gap-1">
                        {restaurants.links.map((link, i) =>
                            link.url ? (
                                <Link
                                    key={i}
                                    href={link.url}
                                    preserveScroll
                                    className={cn(
                                        'rounded-md border px-3 py-1 text-sm',
                                        link.active &&
                                            'bg-primary text-primary-foreground',
                                    )}
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ) : null,
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}

AdminRestaurants.layout = {
    breadcrumbs: [
        {
            title: 'admin_restaurants.title',
            href: RestaurantController.index.url(),
        },
    ],
};
