import { Form, Head, Link } from '@inertiajs/react';
import { BellRing, CheckCheck, Check, Receipt } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import CallController from '@/actions/App/Http/Controllers/Restaurant/CallController';
import TableController from '@/actions/App/Http/Controllers/Restaurant/TableController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useLiveReload } from '@/hooks/use-live-reload';
import { useCanWrite, usePermission } from '@/hooks/use-permission';
import { formatBRL } from '@/lib/money';
import { cn } from '@/lib/utils';

type Call = {
    id: number;
    type: 'waiter' | 'bill';
    created_at: string;
    table: { id: number; title: string };
    requested_by: string | null;
    total: number;
    remaining: number;
};

type Props = { calls: Call[] };

/** Calls older than this stand out: someone has been waiting a while. */
const LATE_MINUTES = 3;

function minutesSince(iso: string): number {
    return Math.max(
        0,
        Math.floor((Date.now() - new Date(iso).getTime()) / 60_000),
    );
}

/**
 * Tables that called the waiter or asked for the bill, oldest first. Refreshes on its
 * own during service; "Atendida" takes the call off the board for everyone.
 */
export default function Calls({ calls }: Props) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language === 'pt_BR' ? 'pt-BR' : 'en-US';
    const canAnswer = useCanWrite('calls');
    const canOpenTables = usePermission('tables') !== 'none';

    // Short interval: a waiting table is the point of this screen.
    useLiveReload({ only: ['calls'], intervalMs: 8_000 });

    const title =
        calls.length > 0
            ? `(${calls.length}) ${t('calls.title')}`
            : t('calls.title');

    return (
        <>
            <Head title={title} />

            <div className="mx-auto w-full max-w-3xl space-y-6 p-4 sm:p-6">
                <Heading
                    title={t('calls.title')}
                    description={t('calls.description')}
                />

                {calls.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed py-14 text-muted-foreground">
                        <CheckCheck className="h-8 w-8 opacity-40" />
                        <p className="text-sm">{t('calls.empty')}</p>
                    </div>
                ) : (
                    <ul className="space-y-3">
                        {calls.map((call) => {
                            const minutes = minutesSince(call.created_at);
                            const late = minutes >= LATE_MINUTES;
                            const isBill = call.type === 'bill';
                            const Icon = isBill ? Receipt : BellRing;

                            return (
                                <li
                                    key={call.id}
                                    className={cn(
                                        'flex flex-wrap items-center gap-3 rounded-xl border bg-card p-4 shadow-xs',
                                        late && 'border-destructive/60',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'flex size-11 shrink-0 items-center justify-center rounded-full',
                                            isBill
                                                ? 'bg-amber-500/15 text-amber-600 dark:text-amber-400'
                                                : 'bg-primary/15 text-primary',
                                        )}
                                    >
                                        <Icon className="h-5 w-5" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-base font-bold">
                                            {canOpenTables ? (
                                                <Link
                                                    href={TableController.show.url(
                                                        {
                                                            table: call.table
                                                                .id,
                                                        },
                                                    )}
                                                    className="hover:underline"
                                                >
                                                    {call.table.title}
                                                </Link>
                                            ) : (
                                                call.table.title
                                            )}
                                        </p>
                                        <p className="text-sm">
                                            {isBill
                                                ? t('calls.type_bill')
                                                : t('calls.type_waiter')}
                                            {isBill && call.total > 0 && (
                                                <span className="text-muted-foreground tabular-nums">
                                                    {' · '}
                                                    {t('calls.remaining', {
                                                        amount: formatBRL(
                                                            call.remaining,
                                                            locale,
                                                        ),
                                                    })}
                                                </span>
                                            )}
                                        </p>
                                        <p
                                            className={cn(
                                                'text-xs',
                                                late
                                                    ? 'font-semibold text-destructive'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {minutes < 1
                                                ? t('calls.just_now')
                                                : t('calls.minutes_ago', {
                                                      count: minutes,
                                                  })}
                                            {call.requested_by &&
                                                ` · ${call.requested_by}`}
                                        </p>
                                    </div>
                                    {canAnswer && (
                                        <Form
                                            {...CallController.answer.form({
                                                call: call.id,
                                            })}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="h-11 shrink-0 px-5"
                                                >
                                                    <Check className="h-4 w-4" />
                                                    {t('calls.answer')}
                                                </Button>
                                            )}
                                        </Form>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </>
    );
}

Calls.layout = {
    breadcrumbs: [{ title: 'calls.title', href: CallController.index.url() }],
};
