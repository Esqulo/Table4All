import { router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import IncentiveController from '@/actions/App/Http/Controllers/Restaurant/IncentiveController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDiscount } from '@/lib/discount';
import type { LoyaltyTier } from '@/types';
import SegmentedProgress from './segmented-progress';

/** A tier as typed: inputs hold strings until saved. */
type Row = {
    key: number;
    visits: string;
    discount_type: LoyaltyTier['discount_type'];
    discount_value: string;
    max_discount: string;
};

let nextKey = 0;

function toRow(tier: LoyaltyTier): Row {
    return {
        key: nextKey++,
        visits: String(tier.visits),
        discount_type: tier.discount_type,
        discount_value: String(tier.discount_value),
        max_discount:
            tier.max_discount === null ? '' : String(tier.max_discount),
    };
}

/**
 * The restaurant's loyalty ladder: rows of "at N visits, this discount", with
 * a live preview of the bar customers will see. Saved as a whole.
 */
export default function TierEditor({
    tiers,
    maxTiers,
    maxVisits,
    disabled,
}: {
    tiers: LoyaltyTier[];
    maxTiers: number;
    maxVisits: number;
    disabled: boolean;
}) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language.replace('_', '-');
    const [rows, setRows] = useState<Row[]>(() => tiers.map(toRow));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [dirty, setDirty] = useState(false);

    const update = (key: number, patch: Partial<Row>) => {
        setRows((current) =>
            current.map((row) =>
                row.key === key ? { ...row, ...patch } : row,
            ),
        );
        setDirty(true);
    };

    // Valid rows, in ladder order, for the preview.
    const ladder = rows
        .map((row) => ({
            visits: Number(row.visits),
            discount_type: row.discount_type,
            discount_value: Number(row.discount_value),
            max_discount:
                row.discount_type === 'percent' && row.max_discount !== ''
                    ? Number(row.max_discount)
                    : null,
        }))
        .filter(
            (tier) =>
                Number.isInteger(tier.visits) &&
                tier.visits > 0 &&
                tier.visits <= maxVisits &&
                tier.discount_value > 0,
        )
        .sort((a, b) => a.visits - b.visits);
    const top = ladder.length > 0 ? ladder[ladder.length - 1].visits : 1;

    const save = () => {
        router.put(
            IncentiveController.updateLoyaltyTiers.url(),
            {
                tiers: rows.map((row) => ({
                    visits: row.visits,
                    discount_type: row.discount_type,
                    discount_value: row.discount_value,
                    max_discount:
                        row.discount_type === 'percent' ? row.max_discount : '',
                })),
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onError: (e) => setErrors(e),
                onSuccess: () => {
                    setErrors({});
                    setDirty(false);
                },
            },
        );
    };

    return (
        <div className="space-y-4">
            <div className="max-w-md space-y-1.5">
                <p className="text-xs font-medium text-muted-foreground">
                    {t('incentives.loyalty.preview')}
                </p>
                <SegmentedProgress
                    segments={top}
                    filled={Math.max(0, (ladder[0]?.visits ?? 1) - 1)}
                    checkpoints={ladder.map((tier) => tier.visits)}
                    label={t('incentives.loyalty.preview')}
                    disabled={disabled}
                />
            </div>

            <div className="space-y-2">
                <div className="hidden grid-cols-[6rem_8rem_7rem_7rem_2.25rem] gap-2 px-1 text-xs text-muted-foreground sm:grid">
                    <span>{t('incentives.tiers.visits')}</span>
                    <span>{t('incentives.tiers.type')}</span>
                    <span>{t('incentives.tiers.value')}</span>
                    <span>{t('incentives.tiers.max_discount')}</span>
                </div>
                {rows.map((row, i) => {
                    const error = (field: string) =>
                        errors[`tiers.${i}.${field}`];

                    return (
                        <div
                            key={row.key}
                            className="rounded-xl border p-2 sm:border-0 sm:p-0"
                        >
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-[6rem_8rem_7rem_7rem_2.25rem] sm:items-center">
                                <Input
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    max={maxVisits}
                                    step={1}
                                    value={row.visits}
                                    onChange={(e) =>
                                        update(row.key, {
                                            visits: e.target.value,
                                        })
                                    }
                                    aria-label={t('incentives.tiers.visits')}
                                    aria-invalid={!!error('visits')}
                                />
                                <Select
                                    value={row.discount_type}
                                    onValueChange={(value) =>
                                        update(row.key, {
                                            discount_type:
                                                value as Row['discount_type'],
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        className="w-full"
                                        aria-label={t('incentives.tiers.type')}
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="fixed">
                                            {t('coupons.type_fixed')}
                                        </SelectItem>
                                        <SelectItem value="percent">
                                            {t('coupons.type_percent')}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <Input
                                    type="number"
                                    inputMode="decimal"
                                    min={0.01}
                                    max={
                                        row.discount_type === 'percent'
                                            ? 100
                                            : 99999
                                    }
                                    step={0.01}
                                    value={row.discount_value}
                                    onChange={(e) =>
                                        update(row.key, {
                                            discount_value: e.target.value,
                                        })
                                    }
                                    aria-label={
                                        row.discount_type === 'percent'
                                            ? t('coupons.value_percent')
                                            : t('coupons.value_fixed')
                                    }
                                    aria-invalid={!!error('discount_value')}
                                />
                                <Input
                                    type="number"
                                    inputMode="decimal"
                                    min={0.01}
                                    step={0.01}
                                    value={
                                        row.discount_type === 'percent'
                                            ? row.max_discount
                                            : ''
                                    }
                                    disabled={row.discount_type !== 'percent'}
                                    placeholder={
                                        row.discount_type === 'percent'
                                            ? t('coupons.optional')
                                            : '—'
                                    }
                                    onChange={(e) =>
                                        update(row.key, {
                                            max_discount: e.target.value,
                                        })
                                    }
                                    aria-label={t(
                                        'incentives.tiers.max_discount',
                                    )}
                                    aria-invalid={!!error('max_discount')}
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    disabled={rows.length <= 1}
                                    onClick={() => {
                                        setRows((current) =>
                                            current.filter(
                                                (r) => r.key !== row.key,
                                            ),
                                        );
                                        setDirty(true);
                                    }}
                                    className="col-span-2 w-full text-destructive hover:text-destructive sm:col-span-1 sm:w-9"
                                    aria-label={t('incentives.tiers.remove')}
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
                            <InputError
                                className="mt-1"
                                message={
                                    error('visits') ??
                                    error('discount_value') ??
                                    error('discount_type') ??
                                    error('max_discount')
                                }
                            />
                        </div>
                    );
                })}
                <InputError message={errors.tiers} />
            </div>

            {ladder.length > 0 && (
                <p className="text-sm text-muted-foreground">
                    {ladder
                        .map((tier) =>
                            t('incentives.tiers.summary', {
                                count: tier.visits,
                                value: formatDiscount(tier, t, locale),
                            }),
                        )
                        .join(' → ')}
                    {' · '}
                    {t('incentives.tiers.restarts')}
                </p>
            )}

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={rows.length >= maxTiers}
                    onClick={() => {
                        const last = ladder[ladder.length - 1]?.visits ?? 0;
                        setRows((current) => [
                            ...current,
                            toRow({
                                visits: Math.min(maxVisits, last + 5),
                                discount_type: 'fixed',
                                discount_value: 10,
                                max_discount: null,
                            }),
                        ]);
                        setDirty(true);
                    }}
                >
                    <Plus className="size-4" />
                    {t('incentives.tiers.add')}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    disabled={saving || !dirty}
                    onClick={save}
                >
                    {t('incentives.tiers.save')}
                </Button>
                <span className="text-xs text-muted-foreground">
                    {t('incentives.tiers.limits', {
                        tiers: maxTiers,
                        visits: maxVisits,
                    })}
                </span>
            </div>
        </div>
    );
}
