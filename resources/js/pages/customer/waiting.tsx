import { Head, Link, router } from '@inertiajs/react';
import { Clock, XCircle } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import JoinController from '@/actions/App/Http/Controllers/Customer/JoinController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useLiveReload } from '@/hooks/use-live-reload';

type Props = {
    code: string;
    table: string;
    restaurant: string | null;
    status: 'pending' | 'rejected';
    attemptsLeft: number;
};

/** Shown to a customer until the table's responsible lets them in. */
export default function CustomerWaiting({
    code,
    table,
    restaurant,
    status,
    attemptsLeft,
}: Props) {
    const { t } = useTranslation();
    const pending = status === 'pending';
    const [busy, setBusy] = useState(false);

    function post(url: string) {
        if (busy) {
            return;
        }

        setBusy(true);
        router.post(url, {}, { onFinish: () => setBusy(false) });
    }

    // Once approved, the same URL answers with the table page and Inertia swaps to it.
    useLiveReload({ only: ['status'], intervalMs: 5_000, enabled: pending });

    return (
        <>
            <Head title={t('join_table.title')} />

            <div className="flex flex-col items-center gap-6 px-4 py-24 text-center">
                {pending ? (
                    <Clock className="h-12 w-12 text-primary" />
                ) : (
                    <XCircle className="h-12 w-12 text-destructive" />
                )}

                <div className="max-w-sm space-y-2">
                    <p className="text-sm text-muted-foreground">
                        {restaurant ? `${restaurant} · ${table}` : table}
                    </p>
                    <h1 className="font-display text-[1.75rem] leading-tight font-extrabold text-balance">
                        {pending
                            ? t('join_table.waiting_title')
                            : t('join_table.rejected_title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {pending
                            ? t('join_table.waiting_description')
                            : attemptsLeft > 0
                              ? t('join_table.rejected_attempts_left', {
                                    count: attemptsLeft,
                                })
                              : t('join_table.rejected_limit')}
                    </p>
                </div>

                {pending ? (
                    <>
                        <Spinner className="h-5 w-5 text-muted-foreground" />
                        <Button
                            variant="ghost"
                            disabled={busy}
                            onClick={() => post(JoinController.leave.url(code))}
                        >
                            {t('join_table.cancel_request')}
                        </Button>
                    </>
                ) : (
                    <div className="flex flex-wrap justify-center gap-2">
                        {attemptsLeft > 0 && (
                            <Button
                                disabled={busy}
                                onClick={() =>
                                    post(JoinController.requestAgain.url(code))
                                }
                            >
                                {t('join_table.request_again')}
                            </Button>
                        )}
                        <Button asChild variant="outline">
                            <Link href={JoinController.index.url()}>
                                {t('nav.table')}
                            </Link>
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

CustomerWaiting.layout = {
    breadcrumbs: [
        { title: 'join_table.title', href: JoinController.index.url() },
    ],
};
