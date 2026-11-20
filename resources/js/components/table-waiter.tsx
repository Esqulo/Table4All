import { router } from '@inertiajs/react';
import { Hand, LogOut, UserRound, UserX } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import TableController from '@/actions/App/Http/Controllers/Restaurant/TableController';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

/** What the tables screens may show and offer about the waiter in charge (TableAssignment). */
export type AssignmentContext = {
    /** The restaurant turned waiter assignment on. */
    enabled: boolean;
    /** Owner or manager: may give any table to anyone on the team. */
    canAssign: boolean;
    /** A team member who works the tables: takes free tables, lets go of their own. */
    canServe: boolean;
    /** The viewer's id. */
    me: number;
};

export type TableWaiter = { id: number; name: string } | null;

const NOBODY = 'none';

/** "Garçom: Ana", "Você", or "Sem garçom". */
export function WaiterBadge({
    waiter,
    me,
    className,
}: {
    waiter: TableWaiter;
    me: number;
    className?: string;
}) {
    const { t } = useTranslation();
    const mine = waiter !== null && waiter.id === me;

    return (
        <span
            className={cn(
                'inline-flex max-w-full items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                waiter === null
                    ? 'bg-amber-500/15 text-amber-700 dark:text-amber-400'
                    : mine
                      ? 'bg-primary/15 text-primary'
                      : 'bg-muted text-muted-foreground',
                className,
            )}
        >
            {waiter === null ? (
                <UserX className="h-3 w-3 shrink-0" />
            ) : (
                <UserRound className="h-3 w-3 shrink-0" />
            )}
            <span className="truncate">
                {waiter === null
                    ? t('tables.waiter_none')
                    : mine
                      ? t('tables.waiter_you')
                      : waiter.name}
            </span>
        </span>
    );
}

/** Take a free table, or let go of one's own. */
export function ClaimReleaseButton({
    tableId,
    waiter,
    assignment,
    size = 'sm',
    className,
}: {
    tableId: number;
    waiter: TableWaiter;
    assignment: AssignmentContext;
    size?: 'sm' | 'default';
    className?: string;
}) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);

    if (!assignment.enabled || !assignment.canServe) {
        return null;
    }

    const options = {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
    };

    if (waiter === null) {
        return (
            <Button
                type="button"
                size={size}
                disabled={busy}
                className={className}
                onClick={() =>
                    router.patch(
                        TableController.claim.url({ table: tableId }),
                        {},
                        options,
                    )
                }
            >
                <Hand className="h-4 w-4" />
                {t('tables.claim')}
            </Button>
        );
    }

    if (waiter.id === assignment.me) {
        return (
            <Button
                type="button"
                size={size}
                variant="outline"
                disabled={busy}
                className={className}
                onClick={() =>
                    confirm(t('tables.release_confirm')) &&
                    router.patch(
                        TableController.release.url({ table: tableId }),
                        {},
                        options,
                    )
                }
            >
                <LogOut className="h-4 w-4" />
                {t('tables.release')}
            </Button>
        );
    }

    return null;
}

/** The owner's or a manager's picker: anyone on the team, or nobody. */
export function AssignWaiterSelect({
    tableId,
    waiter,
    candidates,
}: {
    tableId: number;
    waiter: TableWaiter;
    candidates: { id: number; name: string }[];
}) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);

    // Someone in charge who can no longer be picked (e.g. lost access) still shows.
    const options =
        waiter !== null && !candidates.some((c) => c.id === waiter.id)
            ? [...candidates, waiter]
            : candidates;

    return (
        <Select
            value={waiter === null ? NOBODY : String(waiter.id)}
            disabled={busy}
            onValueChange={(value) =>
                router.put(
                    TableController.assignWaiter.url({ table: tableId }),
                    { waiter_id: value === NOBODY ? null : Number(value) },
                    {
                        preserveScroll: true,
                        onStart: () => setBusy(true),
                        onFinish: () => setBusy(false),
                    },
                )
            }
        >
            <SelectTrigger
                className="w-full sm:w-56"
                aria-label={t('tables.assign_label')}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NOBODY}>
                    {t('tables.waiter_none')}
                </SelectItem>
                {options.map((c) => (
                    <SelectItem key={c.id} value={String(c.id)}>
                        {c.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
