import { router } from '@inertiajs/react';
import { ArrowRightLeft, Combine, Minus, Plus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import TableController from '@/actions/App/Http/Controllers/Restaurant/TableController';
import { ItemNote } from '@/components/item-note';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatBRL } from '@/lib/money';
import { newRequestKey } from '@/lib/request-key';
import type { QueueItem, TableOrderLine } from '@/types';

type Mode = 'items' | 'merge';

const lineKey = (line: TableOrderLine) => `${line.id}_${line.price.toFixed(2)}`;

/**
 * Moves items to another open table (a customer changed tables) or joins this
 * table into another (two groups sat together). The server locks both tables
 * and refuses the move if the items changed meanwhile.
 */
export function TableTransferDialog({
    open,
    onClose,
    table,
    targets,
    orderLines,
    queueItems,
}: {
    open: boolean;
    onClose: () => void;
    table: { id: number; title: string };
    targets: { id: number; title: string }[];
    orderLines: TableOrderLine[];
    queueItems: QueueItem[];
}) {
    const { t } = useTranslation();
    const [mode, setMode] = useState<Mode>('items');
    const [targetId, setTargetId] = useState('');
    // Units of each delivered line to move, and the open items to move whole.
    const [units, setUnits] = useState<Record<string, number>>({});
    const [items, setItems] = useState<Set<number>>(() => new Set());
    const [submitting, setSubmitting] = useState(false);
    // One per transfer: a resent request (slow network, double tap) moves nothing twice.
    const [requestKey, setRequestKey] = useState(newRequestKey);

    const target = targets.find((tb) => String(tb.id) === targetId);
    const selectedUnits = Object.values(units).reduce((a, b) => a + b, 0);
    const selectedCount = selectedUnits + items.size;
    const canSubmit =
        !submitting &&
        target !== undefined &&
        (mode === 'merge' || selectedCount > 0);

    const reset = () => {
        setMode('items');
        setTargetId('');
        setUnits({});
        setItems(new Set());
        setRequestKey(newRequestKey());
    };

    const close = () => {
        reset();
        onClose();
    };

    const changeUnits = (line: TableOrderLine, delta: number) =>
        setUnits((prev) => {
            const key = lineKey(line);
            const next = Math.min(
                line.quantity,
                Math.max(0, (prev[key] ?? 0) + delta),
            );
            const updated = { ...prev, [key]: next };

            if (next === 0) {
                delete updated[key];
            }

            return updated;
        });

    const toggleItem = (id: number, checked: boolean) =>
        setItems((prev) => {
            const next = new Set(prev);

            if (checked) {
                next.add(id);
            } else {
                next.delete(id);
            }

            return next;
        });

    const selectAll = () => {
        setUnits(
            Object.fromEntries(orderLines.map((l) => [lineKey(l), l.quantity])),
        );
        setItems(new Set(queueItems.map((i) => i.id)));
    };

    const submit = () => {
        if (!canSubmit || !target) {
            return;
        }

        const options = {
            preserveScroll: true,
            onStart: () => setSubmitting(true),
            onFinish: () => setSubmitting(false),
            onSuccess: () => close(),
        };

        if (mode === 'merge') {
            if (
                !confirm(
                    t('tables.merge_confirm', {
                        from: table.title,
                        to: target.title,
                    }),
                )
            ) {
                return;
            }

            router.post(
                TableController.merge.url({ table: table.id }),
                { into_table_id: target.id, request_key: requestKey },
                options,
            );

            return;
        }

        router.post(
            TableController.transferItems.url({ table: table.id }),
            {
                to_table_id: target.id,
                lines: orderLines
                    .filter((l) => (units[lineKey(l)] ?? 0) > 0)
                    .map((l) => ({
                        product_id: l.id,
                        price: l.price,
                        quantity: units[lineKey(l)],
                    })),
                queue_item_ids: [...items],
                request_key: requestKey,
            },
            options,
        );
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && close()}>
            <DialogContent className="flex max-h-[85vh] max-w-lg flex-col gap-4 p-0">
                <DialogHeader className="px-6 pt-6">
                    <DialogTitle>{t('tables.transfer_title')}</DialogTitle>
                    <DialogDescription>
                        {t('tables.transfer_description')}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 px-6">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={mode}
                        onValueChange={(v) => v && setMode(v as Mode)}
                        className="w-full"
                    >
                        <ToggleGroupItem value="items" className="flex-1">
                            <ArrowRightLeft className="mr-1.5 h-4 w-4" />
                            {t('tables.transfer_mode_items')}
                        </ToggleGroupItem>
                        <ToggleGroupItem value="merge" className="flex-1">
                            <Combine className="mr-1.5 h-4 w-4" />
                            {t('tables.transfer_mode_merge')}
                        </ToggleGroupItem>
                    </ToggleGroup>

                    <div className="space-y-2">
                        <Label>
                            {mode === 'merge'
                                ? t('tables.merge_into_label')
                                : t('tables.transfer_to_label')}
                        </Label>
                        {targets.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('tables.transfer_no_targets')}
                            </p>
                        ) : (
                            <Select
                                value={targetId}
                                onValueChange={setTargetId}
                            >
                                <SelectTrigger>
                                    <SelectValue
                                        placeholder={t(
                                            'tables.transfer_pick_table',
                                        )}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    {targets.map((tb) => (
                                        <SelectItem
                                            key={tb.id}
                                            value={String(tb.id)}
                                        >
                                            {tb.title}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                    </div>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto border-y border-border">
                    {mode === 'merge' ? (
                        <p className="px-6 py-4 text-sm text-muted-foreground">
                            {t('tables.merge_explanation', {
                                from: table.title,
                                to: target?.title ?? '…',
                            })}
                        </p>
                    ) : orderLines.length === 0 && queueItems.length === 0 ? (
                        <p className="px-6 py-8 text-center text-sm text-muted-foreground">
                            {t('tables.transfer_nothing')}
                        </p>
                    ) : (
                        <div className="divide-y divide-border">
                            <div className="flex items-center justify-between px-6 py-2">
                                <span className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    {t('tables.transfer_pick_items')}
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={selectAll}
                                >
                                    {t('tables.transfer_select_all')}
                                </Button>
                            </div>

                            {orderLines.map((line) => {
                                const chosen = units[lineKey(line)] ?? 0;

                                return (
                                    <div
                                        key={lineKey(line)}
                                        className="flex items-center gap-3 px-6 py-3"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">
                                                {line.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground tabular-nums">
                                                {t('tables.transfer_of', {
                                                    count: line.quantity,
                                                })}{' '}
                                                · {formatBRL(line.price)}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-1">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="icon"
                                                className="h-8 w-8"
                                                disabled={chosen === 0}
                                                onClick={() =>
                                                    changeUnits(line, -1)
                                                }
                                            >
                                                <Minus className="h-3.5 w-3.5" />
                                            </Button>
                                            <span className="w-6 text-center text-sm tabular-nums">
                                                {chosen}
                                            </span>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="icon"
                                                className="h-8 w-8"
                                                disabled={
                                                    chosen >= line.quantity
                                                }
                                                onClick={() =>
                                                    changeUnits(line, 1)
                                                }
                                            >
                                                <Plus className="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}

                            {queueItems.map((item) => (
                                <label
                                    key={item.id}
                                    className="flex cursor-pointer items-start gap-3 px-6 py-3"
                                >
                                    <Checkbox
                                        checked={items.has(item.id)}
                                        onCheckedChange={(c) =>
                                            toggleItem(item.id, c === true)
                                        }
                                        className="mt-0.5"
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-medium">
                                            {item.quantity}× {item.product.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {item.status === 'pending' &&
                                            item.queue_id !== null
                                                ? t('tables.transfer_preparing')
                                                : t(
                                                      'tables.transfer_awaiting_delivery',
                                                  )}
                                        </span>
                                        <ItemNote
                                            notes={item.notes}
                                            className="mt-1 w-fit text-xs"
                                        />
                                    </span>
                                </label>
                            ))}
                        </div>
                    )}
                </div>

                <DialogFooter className="px-6 pb-6">
                    <Button variant="outline" onClick={close}>
                        {t('tables.cancel')}
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={!canSubmit}
                        variant={mode === 'merge' ? 'destructive' : 'default'}
                    >
                        {mode === 'merge'
                            ? t('tables.merge_submit')
                            : t('tables.transfer_submit', {
                                  count: selectedCount,
                              })}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
