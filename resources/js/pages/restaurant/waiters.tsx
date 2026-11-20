import { Form, Head, router, useForm } from '@inertiajs/react';
import {
    Clock,
    Eye,
    Mail,
    Pencil,
    ShieldCheck,
    SlidersHorizontal,
    Trash2,
    UserMinus,
} from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import WaiterController from '@/actions/App/Http/Controllers/Restaurant/WaiterController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import type {
    PermissionLevel,
    StaffFeatureInfo,
    StaffRole,
    Waiter,
    WaiterInvitation,
} from '@/types';

type Props = {
    members: Waiter[];
    invitations: WaiterInvitation[];
    features: StaffFeatureInfo[];
    /** The most the viewer may grant per feature. */
    grantLimits: Record<string, PermissionLevel>;
    /** Only the owner invites managers and changes their access. */
    canManageManagers: boolean;
};

const RANK: Record<PermissionLevel, number> = { none: 0, read: 1, write: 2 };
const LEVELS: PermissionLevel[] = ['none', 'read', 'write'];

export default function Waiters({
    members,
    invitations,
    features,
    grantLimits,
    canManageManagers,
}: Props) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState<Waiter | null>(null);

    const form = useForm<{ email: string; role: StaffRole }>({
        email: '',
        role: 'waiter',
    });

    function handleInvite(e: React.FormEvent) {
        e.preventDefault();
        form.post(WaiterController.store.url(), {
            onSuccess: () => form.reset(),
        });
    }

    return (
        <>
            <Head title={t('waiters.title')} />

            <div className="space-y-8 p-6">
                <Heading
                    title={t('waiters.title')}
                    description={t('waiters.description')}
                />

                {/* invite form */}
                <form
                    onSubmit={handleInvite}
                    className="rounded-lg border border-border bg-card p-5 shadow-xs"
                >
                    <p className="mb-4 text-sm font-medium">
                        {t('waiters.invite_heading')}
                    </p>
                    <div className="flex flex-wrap gap-3">
                        <div className="min-w-56 flex-1 space-y-1">
                            <Label htmlFor="waiter-email">
                                {t('waiters.email_label')}
                            </Label>
                            <Input
                                id="waiter-email"
                                type="email"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                                placeholder={t('waiters.email_placeholder')}
                                autoComplete="off"
                            />
                            <InputError message={form.errors.email} />
                        </div>
                        {canManageManagers && (
                            <div className="space-y-1">
                                <Label htmlFor="waiter-role">
                                    {t('waiters.role_label')}
                                </Label>
                                <Select
                                    value={form.data.role}
                                    onValueChange={(v) =>
                                        form.setData('role', v as StaffRole)
                                    }
                                >
                                    <SelectTrigger
                                        id="waiter-role"
                                        className="w-40"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="waiter">
                                            {t('waiters.role_waiter')}
                                        </SelectItem>
                                        <SelectItem value="manager">
                                            {t('waiters.role_manager')}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                        <div className="flex items-end">
                            <Button type="submit" disabled={form.processing}>
                                <Mail className="mr-2 h-4 w-4" />
                                {t('waiters.invite')}
                            </Button>
                        </div>
                    </div>
                    <p className="mt-2 text-xs text-muted-foreground">
                        {t('waiters.invite_hint')}
                    </p>
                </form>

                {/* pending invitations */}
                {invitations.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                            {t('waiters.pending_heading')}
                        </h2>
                        <ul className="divide-y divide-border rounded-lg border border-border">
                            {invitations.map((inv) => (
                                <li
                                    key={inv.id}
                                    className="flex items-center justify-between gap-3 px-4 py-3"
                                >
                                    <div className="flex min-w-0 items-center gap-3">
                                        <Clock className="h-4 w-4 shrink-0 text-muted-foreground" />
                                        <span className="truncate text-sm text-muted-foreground">
                                            {inv.email}
                                        </span>
                                        <RoleBadge role={inv.role} />
                                    </div>
                                    {inv.cancellable && (
                                        <Form
                                            {...WaiterController.cancelInvitation.form(
                                                { invitation: inv.id },
                                            )}
                                            onBefore={() =>
                                                confirm(
                                                    t('waiters.cancel_confirm'),
                                                )
                                            }
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={processing}
                                                    aria-label={t(
                                                        'waiters.cancel_invite',
                                                    )}
                                                    className="shrink-0 text-muted-foreground hover:text-destructive"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            )}
                                        </Form>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {/* active members */}
                <section className="space-y-3">
                    <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                        {t('waiters.active_heading')}
                    </h2>
                    {members.length === 0 ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">
                            {t('waiters.empty')}
                        </p>
                    ) : (
                        <ul className="divide-y divide-border rounded-lg border border-border">
                            {members.map((member) => (
                                <li
                                    key={member.id}
                                    className="flex flex-wrap items-start justify-between gap-3 px-4 py-3"
                                >
                                    <div className="min-w-0 flex-1 space-y-2">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="truncate text-sm font-medium">
                                                {member.name}
                                            </p>
                                            <RoleBadge role={member.role} />
                                            {member.is_self && (
                                                <span className="text-xs text-muted-foreground">
                                                    {t('waiters.you')}
                                                </span>
                                            )}
                                        </div>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {member.email}
                                        </p>
                                        <AccessSummary
                                            member={member}
                                            features={features}
                                        />
                                    </div>
                                    {member.manageable && (
                                        <div className="flex shrink-0 items-center gap-1">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setEditing(member)
                                                }
                                            >
                                                <SlidersHorizontal className="h-4 w-4" />
                                                {t('waiters.edit_access')}
                                            </Button>
                                            <Form
                                                {...WaiterController.destroy.form(
                                                    { waiter: member.id },
                                                )}
                                                onBefore={() =>
                                                    confirm(
                                                        t(
                                                            'waiters.delete_confirm',
                                                        ),
                                                    )
                                                }
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        type="submit"
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={processing}
                                                        aria-label={t(
                                                            'waiters.remove',
                                                        )}
                                                        className="shrink-0 text-destructive hover:text-destructive"
                                                    >
                                                        <UserMinus className="h-4 w-4" />
                                                    </Button>
                                                )}
                                            </Form>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            {editing && (
                <AccessDialog
                    key={editing.id}
                    member={editing}
                    features={features}
                    grantLimits={grantLimits}
                    canManageManagers={canManageManagers}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function RoleBadge({ role }: { role: StaffRole }) {
    const { t } = useTranslation();

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                role === 'manager'
                    ? 'bg-primary/15 text-primary'
                    : 'bg-muted text-muted-foreground',
            )}
        >
            {role === 'manager' && <ShieldCheck className="h-3 w-3" />}
            {role === 'manager'
                ? t('waiters.role_manager')
                : t('waiters.role_waiter')}
        </span>
    );
}

/** The features the member reaches, each with an eye (read-only) or a pencil (writes). */
function AccessSummary({
    member,
    features,
}: {
    member: Waiter;
    features: StaffFeatureInfo[];
}) {
    const { t } = useTranslation();
    const reached = features.filter(
        (f) => (member.access[f.key] ?? 'none') !== 'none',
    );

    if (reached.length === 0) {
        return (
            <p className="text-xs text-muted-foreground">
                {t('waiters.no_access')}
            </p>
        );
    }

    return (
        <ul className="flex flex-wrap gap-1.5">
            {reached.map((f) => {
                const write = member.access[f.key] === 'write';
                const label = t(`staff.feature_${f.key}`);

                return (
                    <li
                        key={f.key}
                        className="inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[11px]"
                        title={`${label}: ${t(write ? 'staff.level_write' : 'staff.level_read')}`}
                    >
                        {write ? (
                            <Pencil className="h-3 w-3 text-primary" />
                        ) : (
                            <Eye className="h-3 w-3 text-muted-foreground" />
                        )}
                        {label}
                    </li>
                );
            })}
        </ul>
    );
}

function AccessDialog({
    member,
    features,
    grantLimits,
    canManageManagers,
    onClose,
}: {
    member: Waiter;
    features: StaffFeatureInfo[];
    grantLimits: Record<string, PermissionLevel>;
    canManageManagers: boolean;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [role, setRole] = useState<StaffRole>(member.role);
    const [permissions, setPermissions] = useState<
        Record<string, PermissionLevel>
    >(member.permissions);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const save = () => {
        router.put(
            WaiterController.update.url({ waiter: member.id }),
            { role, permissions },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setErrors({});
                },
                onSuccess: onClose,
                onError: setErrors,
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && !processing && onClose()}>
            {/* Header and buttons stay put; only the list of areas scrolls. */}
            <DialogContent
                showCloseButton={false}
                className="flex max-h-[90vh] flex-col gap-0 p-0 sm:max-w-xl"
            >
                <DialogHeader className="border-b px-6 pt-6 pb-4">
                    <DialogTitle>
                        {t('waiters.access_title', { name: member.name })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('waiters.access_description')}
                    </DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-4">
                    {canManageManagers && (
                        <div className="space-y-2">
                            <Label id="access-role-label">
                                {t('waiters.role_label')}
                            </Label>
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                aria-labelledby="access-role-label"
                                value={role}
                                onValueChange={(v) =>
                                    v && setRole(v as StaffRole)
                                }
                                className="w-fit"
                            >
                                <ToggleGroupItem
                                    value="waiter"
                                    className="px-3"
                                >
                                    {t('waiters.role_waiter')}
                                </ToggleGroupItem>
                                <ToggleGroupItem
                                    value="manager"
                                    className="px-3"
                                >
                                    {t('waiters.role_manager')}
                                </ToggleGroupItem>
                            </ToggleGroup>
                            <p className="text-sm text-muted-foreground">
                                {role === 'manager'
                                    ? t('waiters.role_manager_hint')
                                    : t('waiters.role_waiter_hint')}
                            </p>
                        </div>
                    )}

                    <ul className="divide-y rounded-lg border">
                        {features.map((f) => {
                            const chosen = permissions[f.key] ?? 'none';
                            // A manager reads everything: "none" is read-only for them.
                            const value =
                                role === 'manager' && chosen === 'none'
                                    ? 'read'
                                    : chosen;
                            const limit = grantLimits[f.key] ?? 'none';
                            const label = t(`staff.feature_${f.key}`);

                            return (
                                <li
                                    key={f.key}
                                    className="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5"
                                >
                                    <div className="min-w-0">
                                        <p
                                            className="text-sm font-medium"
                                            id={`feature-${f.key}`}
                                        >
                                            {label}
                                        </p>
                                        {f.ceiling === 'read' && (
                                            <p className="text-xs text-muted-foreground">
                                                {t('staff.read_only_feature')}
                                            </p>
                                        )}
                                        <InputError
                                            message={
                                                errors[`permissions.${f.key}`]
                                            }
                                        />
                                    </div>
                                    <ToggleGroup
                                        type="single"
                                        variant="outline"
                                        size="sm"
                                        aria-labelledby={`feature-${f.key}`}
                                        value={value}
                                        onValueChange={(v) =>
                                            v &&
                                            setPermissions({
                                                ...permissions,
                                                [f.key]: v as PermissionLevel,
                                            })
                                        }
                                    >
                                        {LEVELS.filter(
                                            (level) =>
                                                RANK[level] <=
                                                    RANK[f.ceiling] &&
                                                !(
                                                    role === 'manager' &&
                                                    level === 'none'
                                                ),
                                        ).map((level) => (
                                            <ToggleGroupItem
                                                key={level}
                                                value={level}
                                                // Above what the viewer may grant (a manager's own access).
                                                disabled={
                                                    RANK[level] > RANK[limit] &&
                                                    level !== value
                                                }
                                                className="px-2.5 text-xs"
                                            >
                                                {t(`staff.level_${level}`)}
                                            </ToggleGroupItem>
                                        ))}
                                    </ToggleGroup>
                                </li>
                            );
                        })}
                    </ul>
                </div>

                <DialogFooter className="border-t px-6 py-4">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onClose}
                        disabled={processing}
                    >
                        {t('waiters.access_cancel')}
                    </Button>
                    <Button type="button" onClick={save} disabled={processing}>
                        {t('waiters.access_save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

Waiters.layout = {
    breadcrumbs: [
        {
            title: 'waiters.breadcrumb_index',
            href: WaiterController.index.url(),
        },
    ],
};
