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

function fmtTime(iso: string) {
    return new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
}

export default function QueueShow({ queue, items }: Props) {
    const { t } = useTranslation();

    const pending = items.filter((i) => i.status === 'pending');
    const done = items.filter((i) => i.status === 'done');

    // Global arrival position across all items (backend already orders by created_at)
    const position = new Map(items.map((item, idx) => [item.id, idx + 1]));

    return (
        <>
            <Head title={queue.name} />

            <div className="mx-auto max-w-5xl space-y-10 p-6">
                <div>
                    <h1 className="text-2xl font-bold">{queue.name}</h1>
                    <p className="mt-1 text-sm text-muted-foreground">{t('queues.kitchen_subtitle')}</p>
                </div>

                {items.length === 0 && (
                    <p className="rounded-2xl border border-dashed border-border px-6 py-16 text-center text-base text-muted-foreground">
                        {t('queues.items_empty')}
                    </p>
                )}

                {pending.length > 0 && (
                    <section className="space-y-4">
                        <div className="flex items-center gap-2">
                            <Clock className="h-5 w-5 text-muted-foreground" />
                            <h2 className="text-base font-semibold">{t('queues.status_pending')}</h2>
                            <span className="ml-auto rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                                {pending.length}
                            </span>
                        </div>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            {pending.map((item) => (
                                <PendingCard key={item.id} item={item} pos={position.get(item.id)!} />
                            ))}
                        </div>
                    </section>
                )}

                {done.length > 0 && (
                    <section className="space-y-4">
                        <div className="flex items-center gap-2">
                            <CheckCircle2 className="h-5 w-5 text-green-600" />
                            <h2 className="text-base font-semibold">{t('queues.status_done')}</h2>
                            <span className="ml-auto rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                                {done.length}
                            </span>
                        </div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            {done.map((item) => (
                                <DoneCard key={item.id} item={item} pos={position.get(item.id)!} />
                            ))}
                        </div>
                    </section>
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

function PendingCard({ item, pos }: { item: QueueItem; pos: number }) {
    const { t } = useTranslation();
    return (
        <div className="flex flex-col overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
            {/* Image banner with overlaid badges */}
            <div className="relative h-40 w-full bg-muted">
                {item.product.picture_url ? (
                    <img
                        src={item.product.picture_url}
                        alt={item.product.name}
                        className="h-full w-full object-cover"
                    />
                ) : (
                    <div className="flex h-full w-full items-center justify-center text-muted-foreground">
                        <ImageOff className="h-10 w-10 opacity-30" />
                    </div>
                )}
                {/* Arrival order badge */}
                <span className="absolute left-3 top-3 rounded-lg bg-background/90 px-2.5 py-1 text-xs font-bold shadow-sm backdrop-blur-sm">
                    #{pos}
                </span>
                {/* Time badge */}
                <span className="absolute right-3 top-3 rounded-lg bg-background/90 px-2.5 py-1 text-xs text-muted-foreground shadow-sm backdrop-blur-sm">
                    {fmtTime(item.created_at)}
                </span>
                {/* Quantity badge */}
                <span className="absolute bottom-3 right-3 rounded-xl bg-foreground px-3 py-1.5 text-2xl font-black tabular-nums text-background shadow">
                    ×{item.quantity}
                </span>
            </div>

            {/* Product info */}
            <div className="flex-1 px-5 py-4">
                <p className="text-lg font-semibold leading-snug">{item.product.name}</p>
                <p className="mt-1 text-sm font-medium text-muted-foreground">{item.restaurant_table?.title}</p>
            </div>

            {/* Action */}
            <div className="px-4 pb-4">
                <Form {...QueueItemController.markDone.form({ queueItem: item.id })}>
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            className="h-11 w-full text-base"
                        >
                            <Clock className="mr-2 h-4 w-4" />
                            {t('queues.item_mark_done')}
                        </Button>
                    )}
                </Form>
            </div>
        </div>
    );
}

function DoneCard({ item, pos }: { item: QueueItem; pos: number }) {
    const { t } = useTranslation();
    return (
        <div className="flex items-center gap-4 overflow-hidden rounded-2xl border border-border bg-card px-5 py-4 shadow-sm">
            {/* Order number */}
            <span className="shrink-0 text-sm font-bold text-muted-foreground">#{pos}</span>

            {/* Thumbnail */}
            <div className="h-14 w-14 shrink-0 overflow-hidden rounded-xl bg-muted">
                {item.product.picture_url ? (
                    <img src={item.product.picture_url} alt={item.product.name} className="h-full w-full object-cover" />
                ) : (
                    <div className="flex h-full w-full items-center justify-center text-muted-foreground">
                        <ImageOff className="h-5 w-5 opacity-40" />
                    </div>
                )}
            </div>

            {/* Info */}
            <div className="min-w-0 flex-1">
                <p className="truncate font-semibold">{item.product.name}</p>
                <p className="mt-0.5 text-sm text-muted-foreground">
                    {item.restaurant_table?.title} · ×{item.quantity} · {fmtTime(item.created_at)}
                </p>
            </div>

            {/* Action */}
            <Form {...QueueItemController.markDelivered.form({ queueItem: item.id })}>
                {({ processing }) => (
                    <Button type="submit" disabled={processing} className="h-10 shrink-0 px-4">
                        <CheckCircle2 className="mr-1.5 h-4 w-4" />
                        {t('queues.item_mark_delivered')}
                    </Button>
                )}
            </Form>
        </div>
    );
}
