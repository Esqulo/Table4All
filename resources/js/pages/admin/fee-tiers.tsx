import { Form, Head, router, usePage } from '@inertiajs/react';
import { Pencil, Plus, Star, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import FeeTierController from '@/actions/App/Http/Controllers/Admin/FeeTierController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

type Tier = {
    id: number;
    key: string;
    name: string;
    fee_percent: number;
    is_default: boolean;
    restaurants: number;
};

type Editing = { mode: 'new' } | { mode: 'edit'; tier: Tier } | null;

export default function AdminFeeTiers({
    tiers,
    maxPercent,
}: {
    tiers: Tier[];
    maxPercent: number;
}) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language === 'pt_BR' ? 'pt-BR' : 'en-US';
    const [editing, setEditing] = useState<Editing>(null);
    const errors = usePage<{ errors: Record<string, string> }>().props.errors;

    function destroy(tier: Tier) {
        if (
            !window.confirm(
                t('admin_tiers.delete_confirm', { name: tier.name }),
            )
        ) {
            return;
        }

        router.delete(FeeTierController.destroy.url(tier.id), {
            preserveScroll: true,
        });
    }

    return (
        <>
            <Head title={t('admin_tiers.title')} />

            <div className="space-y-6 p-4 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={t('admin_tiers.title')}
                        description={t('admin_tiers.description')}
                    />
                    <Button onClick={() => setEditing({ mode: 'new' })}>
                        <Plus className="size-4" />
                        {t('admin_tiers.new')}
                    </Button>
                </div>

                <InputError message={errors.tier} />

                <ul className="divide-y divide-border rounded-xl border border-border">
                    {tiers.map((tier) => (
                        <li
                            key={tier.id}
                            className="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3"
                        >
                            <div className="min-w-40 flex-1">
                                <p className="flex items-center gap-2 font-medium">
                                    {tier.name}
                                    {tier.is_default && (
                                        <Badge variant="secondary">
                                            <Star className="size-3" />
                                            {t('admin_tiers.default')}
                                        </Badge>
                                    )}
                                </p>
                                <p className="font-mono text-xs text-muted-foreground">
                                    {tier.key}
                                </p>
                            </div>
                            <p className="w-32 font-display text-lg font-bold tabular-nums">
                                {t('admin_tiers.percent', {
                                    percent:
                                        tier.fee_percent.toLocaleString(locale),
                                })}
                            </p>
                            <p className="w-36 text-sm text-muted-foreground">
                                {t('admin_tiers.restaurants', {
                                    count: tier.restaurants,
                                })}
                            </p>
                            <div className="flex gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={t('admin_tiers.edit')}
                                    onClick={() =>
                                        setEditing({ mode: 'edit', tier })
                                    }
                                >
                                    <Pencil className="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={t('admin_tiers.delete')}
                                    disabled={
                                        tier.is_default || tier.restaurants > 0
                                    }
                                    title={
                                        tier.is_default
                                            ? t('admin_tiers.default_locked')
                                            : tier.restaurants > 0
                                              ? t('admin_tiers.in_use')
                                              : undefined
                                    }
                                    onClick={() => destroy(tier)}
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>

                <p className="text-xs text-muted-foreground">
                    {t('admin_tiers.note')}
                </p>
            </div>

            <TierDialog
                editing={editing}
                maxPercent={maxPercent}
                onClose={() => setEditing(null)}
            />
        </>
    );
}

function TierDialog({
    editing,
    maxPercent,
    onClose,
}: {
    editing: Editing;
    maxPercent: number;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const tier = editing?.mode === 'edit' ? editing.tier : null;
    const form = tier
        ? FeeTierController.update.form(tier.id)
        : FeeTierController.store.form();

    return (
        <Dialog open={editing !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {tier ? t('admin_tiers.edit') : t('admin_tiers.new')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('admin_tiers.form_description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    key={tier?.id ?? 'new'}
                    {...form}
                    options={{ preserveScroll: true }}
                    className="space-y-4"
                    onSuccess={onClose}
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="tier_name">
                                    {t('admin_tiers.name')}
                                </Label>
                                <Input
                                    id="tier_name"
                                    name="name"
                                    defaultValue={tier?.name}
                                    required
                                    maxLength={60}
                                    placeholder={t(
                                        'admin_tiers.name_placeholder',
                                    )}
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="tier_fee">
                                    {t('admin_tiers.fee_percent')}
                                </Label>
                                <Input
                                    id="tier_fee"
                                    name="fee_percent"
                                    type="number"
                                    inputMode="decimal"
                                    step="0.01"
                                    min="0"
                                    max={maxPercent}
                                    defaultValue={tier?.fee_percent}
                                    required
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t('admin_tiers.fee_percent_hint', {
                                        max: maxPercent,
                                    })}
                                </p>
                                <InputError message={errors.fee_percent} />
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={onClose}
                                >
                                    {t('admin_tiers.cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {t('admin_tiers.save')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

AdminFeeTiers.layout = {
    breadcrumbs: [
        { title: 'admin_tiers.title', href: FeeTierController.index.url() },
    ],
};
