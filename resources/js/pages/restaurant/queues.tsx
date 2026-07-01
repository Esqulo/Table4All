import { Form, Head, Link } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import QueueController from '@/actions/App/Http/Controllers/Restaurant/QueueController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import type { RestaurantQueue } from '@/types';

type Props = {
    queues: RestaurantQueue[];
};

export default function Queues({ queues }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('queues.title')} />

            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading title={t('queues.title')} />
                    <Button asChild>
                        <Link href={QueueController.create.url()}>
                            <Plus className="mr-2 h-4 w-4" />
                            {t('queues.new')}
                        </Link>
                    </Button>
                </div>

                {queues.length === 0 ? (
                    <p className="py-12 text-center text-sm text-muted-foreground">
                        {t('queues.empty')}
                    </p>
                ) : (
                    <div className="divide-y divide-border rounded-xl border border-border">
                        {queues.map((queue) => (
                            <QueueRow key={queue.id} queue={queue} />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

function QueueRow({ queue }: { queue: RestaurantQueue }) {
    const { t } = useTranslation();

    return (
        <div className="flex items-center justify-between gap-4 px-4 py-3">
            <div>
                <p className="font-medium">{queue.name}</p>
                <p className="text-xs text-muted-foreground">
                    {t('queues.products_count', { count: queue.products_count ?? 0 })}
                </p>
            </div>

            <div className="flex items-center gap-2">
                <Button variant="outline" size="sm" asChild>
                    <Link href={QueueController.edit.url({ queue: queue.id })}>
                        <Pencil className="mr-1 h-3.5 w-3.5" />
                        {t('queues.edit')}
                    </Link>
                </Button>

                <Form
                    {...QueueController.destroy.form({ queue: queue.id })}
                    onBefore={() => confirm(t('queues.delete_confirm'))}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            disabled={processing}
                            className="text-destructive hover:text-destructive"
                        >
                            <Trash2 className="mr-1 h-3.5 w-3.5" />
                            {t('queues.delete')}
                        </Button>
                    )}
                </Form>
            </div>
        </div>
    );
}

Queues.layout = {
    breadcrumbs: [
        {
            title: 'queues.breadcrumb_index',
            href: QueueController.index.url(),
        },
    ],
};
