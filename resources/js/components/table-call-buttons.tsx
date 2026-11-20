import { router } from '@inertiajs/react';
import { BellRing, Loader2, Receipt, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import JoinController from '@/actions/App/Http/Controllers/Customer/JoinController';
import { cn } from '@/lib/utils';

export type CallType = 'waiter' | 'bill';

/** The table's open calls: when each was made (absent when not called). */
export type TableCalls = Partial<Record<CallType, string | null>>;

/**
 * "Chamar o garçom" and "Pedir a conta" on the customer's table screen. Once called,
 * the button says so until staff answer it (the screen polls `calls`); anyone at the
 * table can take the call back. A repeated tap is harmless: one open call per type.
 */
export function TableCallButtons({
    code,
    calls,
}: {
    code: string;
    calls: TableCalls;
}) {
    return (
        <div className="grid grid-cols-2 gap-2">
            <CallButton code={code} type="waiter" since={calls.waiter} />
            <CallButton code={code} type="bill" since={calls.bill} />
        </div>
    );
}

function CallButton({
    code,
    type,
    since,
}: {
    code: string;
    type: CallType;
    since: string | null | undefined;
}) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);
    const open = since !== undefined;
    const Icon = type === 'bill' ? Receipt : BellRing;

    const options = {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
    };

    const call = () =>
        router.post(JoinController.call.url({ code }), { type }, options);

    const cancel = () =>
        router.delete(JoinController.cancelCall.url({ code, type }), options);

    if (open) {
        return (
            <div
                role="status"
                className="flex min-h-12 items-center gap-2 rounded-xl border border-primary/40 bg-primary/10 py-1.5 pr-1.5 pl-3 text-sm"
            >
                <Icon className="h-4 w-4 shrink-0 animate-pulse text-primary" />
                <span className="min-w-0 flex-1 leading-tight font-medium">
                    {t(`join_table.call_waiting_${type}`)}
                </span>
                <button
                    type="button"
                    onClick={cancel}
                    disabled={busy}
                    aria-label={t('join_table.call_cancel')}
                    title={t('join_table.call_cancel')}
                    className="flex size-8 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:bg-background disabled:opacity-50"
                >
                    {busy ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                        <X className="h-4 w-4" />
                    )}
                </button>
            </div>
        );
    }

    return (
        <button
            type="button"
            onClick={call}
            disabled={busy}
            className={cn(
                'flex min-h-12 items-center justify-center gap-2 rounded-xl border bg-card px-3 text-sm font-semibold shadow-xs transition-colors hover:bg-muted disabled:opacity-60',
            )}
        >
            {busy ? (
                <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
                <Icon className="h-4 w-4" />
            )}
            {t(`join_table.call_${type}`)}
        </button>
    );
}
