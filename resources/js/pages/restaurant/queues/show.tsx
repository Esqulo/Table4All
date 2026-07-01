import { Form, Head, Link } from '@inertiajs/react';
import { CheckCircle2, Clock, ImageOff } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import QueueController from '@/actions/App/Http/Controllers/Restaurant/QueueController';
import QueueItemController from '@/actions/App/Http/Controllers/Restaurant/QueueItemController';
import { Button } from '@/components/ui/button';
import type { QueueItem, RestaurantQueue } from '@/types';

type Props = {
    queue: RestaurantQueue;
    items: QueueItem[];
};

export default function QueueShow({ queue, items }: Props) {
    const { t } = useTranslation();

    const pending = items.filter((i) => i.status === 'pending');
    const done = items.filter((i) => i.status === 'done');

    return (
        <>
            <Head title={queue.name} />

            <div className="mx-auto max-w-2xl space-y-6 p-6">
                <div>
                    <h1 className="text-xl font-semibold">{queue.name}</h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">{t('queues.kitchen_subtitle')}</p>
                </div>

                {items.length === 0 && (
                    <p className="rounded-xl border border-dashed border-border px-5 py-8 text-center text-sm text-muted-foreground">
                        {t('queues.items_empty')}
                    </p>
                )}

                {pending.length > 0 && (
                    <div className="space-y-3">
                        <div className="flex items-center gap-2">
                            <Clock className="h-4 w-4 text-muted-foreground" />
                            <p className="text-sm font-semibold">{t('queues.status_pending')}</p>
                        </div>
                        <div className="overflow-hidden rounded-xl border border-border">
                            {pending.map((item, idx) => (
                                <QueueItemRow key={item.id} item={item} idx={idx} action="done" />
                            ))}
                        </div>
                    </div>
                )}

                {done.length > 0 && (
                    <div className="space-y-3">
                        <div className="flex items-center gap-2">
                            <CheckCircle2 className="h-4 w-4 text-green-600" />
                            <p className="text-sm font-semibold">{t('queues.status_done')}</p>
                        </div>
                        <div className="overflow-hidden rounded-xl border border-border">
                            {done.map((item, idx) => (
                                <QueueItemRow key={item.id} item={item} idx={idx} action="delivered" />
                            ))}
                        </div>
                    </div>
                )}

                <div className="border-t border-border pt-4">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={QueueController.index.url()}>{t('queues.back_to_queues')}</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}

QueueShow.layout = {
    breadcrumbs: [
        { title: 'queues.breadcrumb_index', href: QueueController.index.url() },
        { title: 'queues.breadcrumb_show', href: '#' },
    ],
};

function QueueItemRow({ item, idx, action }: { item: QueueItem; idx: number; action: 'done' | 'delivered' }) {
    const { t } = useTranslation();
    return (
        <div className={['flex items-center gap-3 px-4 py-3', idx > 0 ? 'border-t border-border' : ''].join(' ')}>
            <div className="h-10 w-10 shrink-0 overflow-hidden rounded-lg bg-muted">
                {item.product.picture_url ? (
                    <img src={item.product.picture_url} alt={item.product.name} className="h-full w-full object-cover" />
                ) : (
                    <div className="flex h-full w-full items-center justify-center text-muted-foreground">
                        <ImageOff className="h-3.5 w-3.5 opacity-40" />
                    </div>
                )}
            </div>
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{item.product.name}</p>
                <p className="text-xs text-muted-foreground">
                    {item.restaurant_table?.title} · x{item.quantity}
                </p>
            </div>
            {action === 'done' ? (
                <Form {...QueueItemController.markDone.form({ queueItem: item.id })}>
                    {({ processing }) => (
                        <Button type="submit" size="sm" variant="outline" disabled={processing} className="shrink-0">
                            <Clock className="mr-1.5 h-3.5 w-3.5" />
                            {t('queues.item_mark_done')}
                        </Button>
                    )}
                </Form>
            ) : (
                <Form {...QueueItemController.markDelivered.form({ queueItem: item.id })}>
                    {({ processing }) => (
                        <Button type="submit" size="sm" disabled={processing} className="shrink-0">
                            <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />
                            {t('queues.item_mark_delivered')}
                        </Button>
                    )}
                </Form>
            )}
        </div>
    );
}
