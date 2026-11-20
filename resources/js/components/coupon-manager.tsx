import { Form, Head } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type RestaurantCouponController from '@/actions/App/Http/Controllers/Restaurant/CouponController';
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
import { formatBRL } from '@/lib/money';

export type CouponRow = {
    id: number;
    code: string;
    discount_type: 'percent' | 'fixed';
    discount_value: number;
    max_discount: number | null;
    expires_on: string | null;
    expired: boolean;
    multiple_per_table: boolean;
    /** Total uses allowed; null for unlimited. */
    max_uses: number | null;
    uses: number;
    exhausted: boolean;
};

/** The restaurant and admin coupon controllers have the same actions. */
type CouponActions = Pick<
    typeof RestaurantCouponController,
    'store' | 'update' | 'destroy'
>;

type Editing = { mode: 'create' } | { mode: 'edit'; coupon: CouponRow } | null;

/**
 * Coupon list + create/edit dialog, shared by the restaurant's own coupons and
 * the admin's platform coupons.
 */
export function CouponManager({
    coupons,
    actions,
    title,
    description,
    readOnly = false,
}: {
    coupons: CouponRow[];
    actions: CouponActions;
    title: string;
    description: string;
    /** Staff with read-only access: the coupons are listed, nothing can be changed. */
    readOnly?: boolean;
}) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState<Editing>(null);

    return (
        <>
            <Head title={title} />

            <div className="space-y-6 p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title={title} description={description} />
                    {!readOnly && (
                        <Button onClick={() => setEditing({ mode: 'create' })}>
                            <Plus className="h-4 w-4" />
                            {t('coupons.new')}
                        </Button>
                    )}
                </div>

                {coupons.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-12 text-center text-sm text-muted-foreground">
                        {t('coupons.empty')}
                    </p>
                ) : (
                    <fieldset disabled={readOnly} className="min-w-0">
                        <ul className="divide-y divide-border rounded-xl border border-border">
                            {coupons.map((coupon) => (
                                <CouponItem
                                    key={coupon.id}
                                    coupon={coupon}
                                    actions={actions}
                                    onEdit={() =>
                                        setEditing({ mode: 'edit', coupon })
                                    }
                                />
                            ))}
                        </ul>
                    </fieldset>
                )}
            </div>

            <CouponDialog
                editing={editing}
                actions={actions}
                onClose={() => setEditing(null)}
            />
        </>
    );
}

function describeDiscount(
    coupon: Pick<
        CouponRow,
        'discount_type' | 'discount_value' | 'max_discount'
    >,
    t: (key: string, options?: Record<string, unknown>) => string,
): string {
    const value =
        coupon.discount_type === 'percent'
            ? `${coupon.discount_value.toLocaleString('pt-BR')}%`
            : formatBRL(coupon.discount_value);

    return coupon.max_discount !== null
        ? t('coupons.discount_capped', {
              value,
              max: formatBRL(coupon.max_discount),
          })
        : t('coupons.discount_off', { value });
}

function CouponItem({
    coupon,
    actions,
    onEdit,
}: {
    coupon: CouponRow;
    actions: CouponActions;
    onEdit: () => void;
}) {
    const { t } = useTranslation();

    return (
        <li className="flex flex-wrap items-center gap-3 px-4 py-3">
            <div className="min-w-0 flex-1 space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-sm font-bold tracking-wide">
                        {coupon.code}
                    </span>
                    {coupon.expired && (
                        <Badge variant="outline" className="text-destructive">
                            {t('coupons.expired')}
                        </Badge>
                    )}
                    {coupon.exhausted && (
                        <Badge variant="outline" className="text-destructive">
                            {t('coupons.exhausted')}
                        </Badge>
                    )}
                    <Badge variant="secondary">
                        {coupon.multiple_per_table
                            ? t('coupons.multiple_badge')
                            : t('coupons.exclusive_badge')}
                    </Badge>
                </div>
                <p className="text-sm text-muted-foreground">
                    {describeDiscount(coupon, t)}
                    {' · '}
                    {coupon.expires_on
                        ? t('coupons.valid_until', {
                              date: new Date(
                                  `${coupon.expires_on}T12:00:00`,
                              ).toLocaleDateString('pt-BR'),
                          })
                        : t('coupons.no_expiry')}
                    {' · '}
                    {coupon.max_uses !== null
                        ? t('coupons.uses_of', {
                              count: coupon.uses,
                              max: coupon.max_uses,
                          })
                        : t('coupons.uses', { count: coupon.uses })}
                </p>
            </div>
            <div className="flex gap-1">
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={onEdit}
                    aria-label={t('coupons.edit')}
                >
                    <Pencil className="h-4 w-4" />
                </Button>
                <Form
                    {...actions.destroy.form({ coupon: coupon.id })}
                    onBefore={() => confirm(t('coupons.delete_confirm'))}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="ghost"
                            size="sm"
                            disabled={processing}
                            className="text-destructive hover:text-destructive"
                            aria-label={t('coupons.delete')}
                        >
                            <Trash2 className="h-4 w-4" />
                        </Button>
                    )}
                </Form>
            </div>
        </li>
    );
}

function CouponDialog({
    editing,
    actions,
    onClose,
}: {
    editing: Editing;
    actions: CouponActions;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const coupon = editing?.mode === 'edit' ? editing.coupon : null;
    const [type, setType] = useState<'percent' | 'fixed'>('percent');
    const [prevEditing, setPrevEditing] = useState<Editing>(null);

    if (prevEditing !== editing) {
        setPrevEditing(editing);
        setType(coupon?.discount_type ?? 'percent');
    }

    const form =
        coupon !== null
            ? actions.update.form({ coupon: coupon.id })
            : actions.store.form();

    return (
        <Dialog open={editing !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {coupon ? t('coupons.edit') : t('coupons.new')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('coupons.form_description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    key={coupon?.id ?? 'new'}
                    {...form}
                    className="space-y-4"
                    onSuccess={onClose}
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="code">
                                    {t('coupons.code')}
                                </Label>
                                <Input
                                    id="code"
                                    name="code"
                                    defaultValue={coupon?.code}
                                    required
                                    maxLength={40}
                                    autoComplete="off"
                                    autoCapitalize="characters"
                                    placeholder="BEMVINDO10"
                                    className="font-mono uppercase"
                                />
                                <InputError message={errors.code} />
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="discount_type">
                                        {t('coupons.discount_type')}
                                    </Label>
                                    <Select
                                        name="discount_type"
                                        value={type}
                                        onValueChange={(v) =>
                                            setType(v as 'percent' | 'fixed')
                                        }
                                    >
                                        <SelectTrigger id="discount_type">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="percent">
                                                {t('coupons.type_percent')}
                                            </SelectItem>
                                            <SelectItem value="fixed">
                                                {t('coupons.type_fixed')}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="discount_value">
                                        {type === 'percent'
                                            ? t('coupons.value_percent')
                                            : t('coupons.value_fixed')}
                                    </Label>
                                    <Input
                                        id="discount_value"
                                        name="discount_value"
                                        type="number"
                                        inputMode="decimal"
                                        step="0.01"
                                        min="0.01"
                                        max={type === 'percent' ? 100 : 99999}
                                        defaultValue={coupon?.discount_value}
                                        required
                                    />
                                </div>
                            </div>
                            <InputError
                                message={
                                    errors.discount_type ??
                                    errors.discount_value
                                }
                            />

                            <div className="grid gap-2">
                                <Label htmlFor="max_discount">
                                    {t('coupons.max_discount')}
                                </Label>
                                <Input
                                    id="max_discount"
                                    name="max_discount"
                                    type="number"
                                    inputMode="decimal"
                                    step="0.01"
                                    min="0.01"
                                    defaultValue={coupon?.max_discount ?? ''}
                                    placeholder={t('coupons.optional')}
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t('coupons.max_discount_hint')}
                                </p>
                                <InputError message={errors.max_discount} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="expires_on">
                                    {t('coupons.expires_on')}
                                </Label>
                                <Input
                                    id="expires_on"
                                    name="expires_on"
                                    type="date"
                                    defaultValue={coupon?.expires_on ?? ''}
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t('coupons.expires_on_hint')}
                                </p>
                                <InputError message={errors.expires_on} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="max_uses">
                                    {t('coupons.max_uses')}
                                </Label>
                                <Input
                                    id="max_uses"
                                    name="max_uses"
                                    type="number"
                                    inputMode="numeric"
                                    step="1"
                                    min="1"
                                    defaultValue={coupon?.max_uses ?? ''}
                                    placeholder={t('coupons.unlimited')}
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t('coupons.max_uses_hint')}
                                </p>
                                <InputError message={errors.max_uses} />
                            </div>

                            <label className="flex items-start gap-3 rounded-lg border border-border p-3">
                                <Checkbox
                                    name="multiple_per_table"
                                    value="1"
                                    defaultChecked={
                                        coupon?.multiple_per_table ?? false
                                    }
                                    className="mt-0.5"
                                />
                                <span className="space-y-0.5">
                                    <span className="block text-sm font-medium">
                                        {t('coupons.multiple_per_table')}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {t('coupons.multiple_per_table_hint')}
                                    </span>
                                </span>
                            </label>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={onClose}
                                >
                                    {t('coupons.cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {t('coupons.save')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
