import { Head, router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import PayoutController from '@/actions/App/Http/Controllers/Admin/PayoutController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatBRL } from '@/lib/money';

type Payout = {
    restaurant_id: number;
    name: string | null;
    email: string | null;
    payments_connected: boolean;
    amount: number;
    payments: number;
    since: string | null;
    up_to_id: number;
};

export default function AdminPayouts({ payouts }: { payouts: Payout[] }) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language === 'pt_BR' ? 'pt-BR' : 'en-US';
    const fmt = (n: number) => formatBRL(n, locale);
    const [confirming, setConfirming] = useState<Payout | null>(null);
    const [busy, setBusy] = useState(false);

    function markPaid() {
        if (confirming === null || busy) {
            return;
        }

        setBusy(true);
        router.post(
            PayoutController.store.url(),
            {
                restaurant_id: confirming.restaurant_id,
                up_to_id: confirming.up_to_id,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setConfirming(null);
                },
            },
        );
    }

    return (
        <>
            <Head title={t('admin_payouts.title')} />

            <div className="space-y-6 p-6">
                <Heading
                    title={t('admin_payouts.title')}
                    description={t('admin_payouts.description')}
                />

                {payouts.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-12 text-center text-sm text-muted-foreground">
                        {t('admin_payouts.empty')}
                    </p>
                ) : (
                    <ul className="divide-y divide-border rounded-xl border border-border">
                        {payouts.map((payout) => (
                            <li
                                key={payout.restaurant_id}
                                className="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3"
                            >
                                <div className="min-w-48 flex-1">
                                    <p className="font-medium">{payout.name}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {payout.email}
                                        {!payout.payments_connected &&
                                            ` · ${t('admin_payouts.not_connected')}`}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {t('admin_payouts.payments', {
                                            count: payout.payments,
                                        })}
                                        {payout.since &&
                                            ` · ${t('admin_payouts.since', {
                                                date: new Date(
                                                    payout.since,
                                                ).toLocaleDateString(locale),
                                            })}`}
                                    </p>
                                </div>
                                <span className="text-lg font-bold tabular-nums">
                                    {fmt(payout.amount)}
                                </span>
                                <Button
                                    variant="outline"
                                    onClick={() => setConfirming(payout)}
                                >
                                    <Check className="h-4 w-4" />
                                    {t('admin_payouts.mark_paid')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <Dialog
                open={confirming !== null}
                onOpenChange={(open) => !open && !busy && setConfirming(null)}
            >
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>
                            {t('admin_payouts.mark_paid')}
                        </DialogTitle>
                        <DialogDescription>
                            {confirming &&
                                t('admin_payouts.mark_paid_confirm', {
                                    amount: fmt(confirming.amount),
                                    name: confirming.name ?? '',
                                })}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => setConfirming(null)}
                        >
                            {t('coupons.cancel')}
                        </Button>
                        <Button disabled={busy} onClick={markPaid}>
                            {t('admin_payouts.mark_paid')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

AdminPayouts.layout = {
    breadcrumbs: [
        { title: 'admin_payouts.title', href: PayoutController.index.url() },
    ],
};
