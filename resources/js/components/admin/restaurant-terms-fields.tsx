import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { FeeTierOption } from '@/types';

/**
 * The platform's terms with a restaurant, as form fields: its plan, an own
 * commission that overrides the plan's (empty = the plan's), and whether
 * the platform pays Stripe's fee for it.
 */
export default function RestaurantTermsFields({
    tiers,
    maxPercent,
    defaults,
    errors,
}: {
    tiers: FeeTierOption[];
    maxPercent: number;
    defaults: {
        fee_tier: string;
        fee_percent_override: number | null;
        platform_pays_stripe_fees: boolean;
    };
    errors: Partial<Record<string, string>>;
}) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language === 'pt_BR' ? 'pt-BR' : 'en-US';
    const [tier, setTier] = useState(defaults.fee_tier);
    const [override, setOverride] = useState(
        defaults.fee_percent_override?.toString() ?? '',
    );
    const [sponsored, setSponsored] = useState(
        defaults.platform_pays_stripe_fees,
    );
    const tierPercent = tiers.find((x) => x.key === tier)?.fee_percent ?? 0;
    const effective = override.trim() === '' ? tierPercent : Number(override);

    return (
        <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="fee_tier">
                        {t('admin_restaurants.tier')}
                    </Label>
                    <Select
                        name="fee_tier"
                        value={tier}
                        onValueChange={setTier}
                    >
                        <SelectTrigger id="fee_tier">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {tiers.map((option) => (
                                <SelectItem key={option.key} value={option.key}>
                                    {option.name} ·{' '}
                                    {option.fee_percent.toLocaleString(locale)}%
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.fee_tier} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="fee_percent_override">
                        {t('admin_restaurants.fee_override')}
                    </Label>
                    <Input
                        id="fee_percent_override"
                        name="fee_percent_override"
                        type="number"
                        inputMode="decimal"
                        step="0.01"
                        min="0"
                        max={maxPercent}
                        value={override}
                        onChange={(e) => setOverride(e.target.value)}
                        placeholder={t(
                            'admin_restaurants.fee_override_placeholder',
                        )}
                    />
                    <InputError message={errors.fee_percent_override} />
                </div>
            </div>
            <p className="text-xs text-muted-foreground">
                {t('admin_restaurants.fee_effective', {
                    percent: Number.isFinite(effective)
                        ? effective.toLocaleString(locale)
                        : '–',
                })}
            </p>

            <input
                type="hidden"
                name="platform_pays_stripe_fees"
                value={sponsored ? '1' : '0'}
            />
            <label className="flex items-start gap-3 rounded-lg border border-border p-3">
                <Checkbox
                    checked={sponsored}
                    onCheckedChange={(checked) =>
                        setSponsored(checked === true)
                    }
                    className="mt-0.5"
                />
                <span className="space-y-0.5">
                    <span className="block text-sm font-medium">
                        {t('admin_restaurants.platform_pays_fees')}
                    </span>
                    <span className="block text-xs text-muted-foreground">
                        {t('admin_restaurants.platform_pays_fees_hint')}
                    </span>
                </span>
            </label>
            <InputError message={errors.platform_pays_stripe_fees} />
        </div>
    );
}
