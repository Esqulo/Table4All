import { Form, Head, router } from '@inertiajs/react';
import { Check, CheckCheck, ConciergeBell, ImageOff, PackageCheck } from 'lucide-react';
import { useEffect, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import DeliveryController from '@/actions/App/Http/Controllers/Restaurant/DeliveryController';
import QueueItemController from '@/actions/App/Http/Controllers/Restaurant/QueueItemController';
import { Button } from '@/components/ui/button';

type DeliveryItem = {
    id: number;
    table_id: number;
    table_title: string;
    product: string;
    picture_url: string | null;
    quantity: number;
    price: number;
    status: 'done' | 'pending';
    ordered_by: string | null;
    waiting_since: string;
};

type Props = { items: DeliveryItem[] };

function waitingLabel(iso: string): string {
    const diffMs = Date.now() - new Date(iso).getTime();
    const mins = Math.floor(diffMs / 60_000);

    if (mins < 1) {
        return '< 1 min';
    }

    if (mins === 1) {
        return '1 min';
    }

    return `${mins} min`;
}

export default function DeliveryIndex({ items }: Props) {
    const { t } = useTranslation();

    // Auto-refresh the items every 30 seconds
    useEffect(() => {
        const id = setInterval(() => {
            router.reload({ only: ['items'] });
        }, 30_000);

        return () => clearInterval(id);
    }, []);

    const byTable = useMemo(() => {
        const map = new Map<number, { title: string; items: DeliveryItem[] }>();

        for (const item of items) {
            if (!map.has(item.table_id)) {
                map.set(item.table_id, { title: item.table_title, items: [] });
            }

            map.get(item.table_id)!.items.push(item);
        }

        return [...map.entries()].map(([id, group]) => ({ id, ...group }));
    }, [items]);

    return (
        <>
            <Head title={t('delivery.title')} />

            <div className="mx-auto max-w-3xl space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                        <ConciergeBell className="h-5 w-5" />
                    </div>
                    <div>
                        <h1 className="text-xl font-semibold">{t('delivery.title')}</h1>
                        <p className="text-sm text-muted-foreground">{t('delivery.subtitle')}</p>
                    </div>
                    {items.length > 0 && (
                        <span className="ml-auto flex h-7 min-w-7 items-center justify-center rounded-full bg-destructive px-2 text-sm font-bold text-destructive-foreground tabular-nums">
                            {items.length}
                        </span>
                    )}
                </div>

                {byTable.length === 0 ? (
                    <div className="flex flex-col items-center gap-4 rounded-2xl border border-dashed border-border py-20 text-center text-muted-foreground">
                        <PackageCheck className="h-12 w-12 opacity-20" />
                        <div>
                            <p className="font-medium">{t('delivery.empty_title')}</p>
                            <p className="mt-1 text-sm">{t('delivery.empty_subtitle')}</p>
                        </div>
                    </div>
                ) : (
                    <div className="space-y-4">
                        {byTable.map((group) => (
                            <div key={group.id} className="overflow-hidden rounded-2xl border border-border bg-card">
                                <div className="flex items-center gap-2 border-b border-border bg-muted/40 px-4 py-2.5">
                                    <CheckCheck className="h-4 w-4 text-muted-foreground" />
                                    <span className="text-sm font-semibold">{group.title}</span>
                                    <span className="ml-auto text-xs text-muted-foreground">
                                        {group.items.length} {group.items.length === 1 ? t('delivery.item') : t('delivery.items')}
                                    </span>
                                </div>

                                <div className="divide-y divide-border">
                                    {group.items.map((item) => (
                                        <div key={item.id} className="flex items-center gap-3 px-4 py-3">
                                            <div className="h-12 w-12 shrink-0 overflow-hidden rounded-lg bg-muted">
                                                {item.picture_url ? (
                                                    <img
                                                        src={item.picture_url}
                                                        alt={item.product}
                                                        className="h-full w-full object-cover"
                                                    />
                                                ) : (
                                                    <div className="flex h-full w-full items-center justify-center text-muted-foreground">
                                                        <ImageOff className="h-4 w-4 opacity-30" />
                                                    </div>
                                                )}
                                            </div>

                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-baseline gap-1.5">
                                                    <p className="truncate text-sm font-medium">{item.product}</p>
                                                    <span className="inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary px-1.5 text-xs font-bold tabular-nums text-primary-foreground">
                                                        {item.quantity}
                                                    </span>
                                                </div>
                                                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                                    {item.ordered_by && (
                                                        <span>{item.ordered_by}</span>
                                                    )}
                                                    {item.ordered_by && <span>·</span>}
                                                    <span className={item.status === 'done' ? 'text-green-600 dark:text-green-400' : 'text-blue-600 dark:text-blue-400'}>
                                                        {item.status === 'done' ? t('delivery.status_done') : t('delivery.status_pending')}
                                                    </span>
                                                    <span>·</span>
                                                    <span>{waitingLabel(item.waiting_since)}</span>
                                                </div>
                                            </div>

                                            <Form {...QueueItemController.markDelivered.form({ queueItem: item.id })}>
                                                {({ processing }) => (
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={processing}
                                                        className="shrink-0 gap-1.5"
                                                    >
                                                        <Check className="h-3.5 w-3.5" />
                                                        {t('delivery.deliver')}
                                                    </Button>
                                                )}
                                            </Form>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

DeliveryIndex.layout = {
    breadcrumbs: [{ title: 'delivery.title', href: DeliveryController.index.url() }],
};
