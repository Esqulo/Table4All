import { Form, Head, Link, router } from '@inertiajs/react';
import {
    CheckCircle2,
    ChevronRight,
    CircleDashed,
    Plus,
    Search,
} from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import RestaurantController from '@/actions/App/Http/Controllers/Admin/RestaurantController';
import RestaurantTermsFields from '@/components/admin/restaurant-terms-fields';
import { formatDateTime } from '@/components/dashboard/format';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { FeeTierOption } from '@/types';

type RestaurantRow = {
    id: number;
    name: string;
    email: string;
    fee_tier: string;
    fee_percent_override: number | null;
    platform_pays_stripe_fees: boolean;
    stripe_connected: boolean;
    stripe_charges_enabled: boolean;
    mercadopago_connected: boolean;
    last_seen_at: string | null;
    created_at: string | null;
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    last_page: number;
    total: number;
};

type Props = {
    restaurants: Paginated<RestaurantRow>;
    tiers: FeeTierOption[];
    defaultTier: string;
    maxPercent: number;
    search: string;
    tier: string;
};

const ALL = '__all';

export default function AdminRestaurants({
    restaurants,
    tiers,
    defaultTier,
    maxPercent,
    search,
    tier,
}: Props) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language === 'pt_BR' ? 'pt-BR' : 'en-US';
    const [query, setQuery] = useState(search);
    const [saving, setSaving] = useState<number | null>(null);
    const [creating, setCreating] = useState(false);

    function filter(changes: { q?: string; tier?: string }) {
        const params = { q: query.trim(), tier, ...changes };
        router.get(
            RestaurantController.index.url(),
            Object.fromEntries(
                Object.entries(params).filter(([, v]) => v !== ''),
            ),
            { preserveState: true },
        );
    }

    function save(
        restaurant: RestaurantRow,
        changes: Partial<
            Pick<RestaurantRow, 'fee_tier' | 'platform_pays_stripe_fees'>
        >,
    ) {
        setSaving(restaurant.id);
        // The own commission isn't sent, so it stays as it is.
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

    const tierLabel = (option: FeeTierOption) =>
        `${option.name} · ${option.fee_percent.toLocaleString(locale)}%`;

    return (
        <>
            <Head title={t('admin_restaurants.title')} />

            <div className="space-y-6 p-4 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={t('admin_restaurants.title')}
                        description={t('admin_restaurants.description')}
                    />
                    <Button onClick={() => setCreating(true)}>
                        <Plus className="size-4" />
                        {t('admin_restaurants.new')}
                    </Button>
                </div>

                <div className="flex flex-wrap gap-3">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            filter({ q: query.trim() });
                        }}
                        className="relative w-full max-w-sm"
                    >
                        <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder={t('admin_restaurants.search')}
                            className="pl-9"
                        />
                    </form>
                    <div className="w-56">
                        <Select
                            value={tier === '' ? ALL : tier}
                            onValueChange={(value) =>
                                filter({ tier: value === ALL ? '' : value })
                            }
                        >
                            <SelectTrigger
                                aria-label={t('admin_restaurants.filter_tier')}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    {t('admin_restaurants.all_tiers')}
                                </SelectItem>
                                {tiers.map((option) => (
                                    <SelectItem
                                        key={option.key}
                                        value={option.key}
                                    >
                                        {option.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <p className="text-sm text-muted-foreground">
                    {t('admin_restaurants.count', { count: restaurants.total })}
                </p>

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
                                <Link
                                    href={RestaurantController.show.url(
                                        restaurant.id,
                                    )}
                                    className="group min-w-48 flex-1"
                                >
                                    <p className="flex items-center gap-1 font-medium group-hover:underline">
                                        {restaurant.name}
                                        <ChevronRight className="size-4 text-muted-foreground" />
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {restaurant.email}
                                    </p>
                                    <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                        {restaurant.stripe_charges_enabled ? (
                                            <span className="inline-flex items-center gap-1">
                                                <CheckCircle2 className="h-3.5 w-3.5 text-green-600" />
                                                {t(
                                                    'admin_restaurants.stripe_active',
                                                )}
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1 text-muted-foreground">
                                                <CircleDashed className="h-3.5 w-3.5" />
                                                {restaurant.stripe_connected
                                                    ? t(
                                                          'admin_restaurants.stripe_pending',
                                                      )
                                                    : t(
                                                          'admin_restaurants.stripe_none',
                                                      )}
                                            </span>
                                        )}
                                        {restaurant.mercadopago_connected && (
                                            <span className="inline-flex items-center gap-1">
                                                <CheckCircle2 className="h-3.5 w-3.5 text-green-600" />
                                                {t(
                                                    'admin_restaurants.mercadopago_active',
                                                )}
                                            </span>
                                        )}
                                        <span className="text-muted-foreground">
                                            {restaurant.last_seen_at
                                                ? t(
                                                      'admin_restaurants.last_seen',
                                                      {
                                                          when: formatDateTime(
                                                              restaurant.last_seen_at,
                                                              i18n.language,
                                                          ),
                                                      },
                                                  )
                                                : t(
                                                      'admin_restaurants.never_seen',
                                                  )}
                                        </span>
                                    </p>
                                </Link>

                                <div className="flex w-56 flex-col gap-1">
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
                                            {tiers.map((option) => (
                                                <SelectItem
                                                    key={option.key}
                                                    value={option.key}
                                                >
                                                    {tierLabel(option)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {restaurant.fee_percent_override !==
                                        null && (
                                        <Badge
                                            variant="outline"
                                            className="self-start"
                                        >
                                            {t('admin_restaurants.own_fee', {
                                                percent:
                                                    restaurant.fee_percent_override.toLocaleString(
                                                        locale,
                                                    ),
                                            })}
                                        </Badge>
                                    )}
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

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('admin_restaurants.new')}</DialogTitle>
                        <DialogDescription>
                            {t('admin_restaurants.new_description')}
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        {...RestaurantController.store.form()}
                        className="space-y-4"
                        onSuccess={() => setCreating(false)}
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="new_name">
                                        {t('admin_restaurants.name')}
                                    </Label>
                                    <Input
                                        id="new_name"
                                        name="name"
                                        required
                                        maxLength={255}
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="new_email">
                                            {t('admin_restaurants.email')}
                                        </Label>
                                        <Input
                                            id="new_email"
                                            name="email"
                                            type="email"
                                            required
                                            autoComplete="off"
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="new_phone">
                                            {t('admin_restaurants.phone')}
                                        </Label>
                                        <Input
                                            id="new_phone"
                                            name="phone"
                                            type="tel"
                                            maxLength={30}
                                            placeholder={t(
                                                'admin_restaurants.optional',
                                            )}
                                        />
                                        <InputError message={errors.phone} />
                                    </div>
                                </div>
                                <RestaurantTermsFields
                                    tiers={tiers}
                                    maxPercent={maxPercent}
                                    defaults={{
                                        fee_tier: defaultTier,
                                        fee_percent_override: null,
                                        platform_pays_stripe_fees: false,
                                    }}
                                    errors={errors}
                                />
                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setCreating(false)}
                                    >
                                        {t('admin_restaurants.cancel')}
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {t('admin_restaurants.create')}
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
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
