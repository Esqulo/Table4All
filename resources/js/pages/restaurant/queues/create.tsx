import { Form, Head, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import QueueController from '@/actions/App/Http/Controllers/Restaurant/QueueController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function CreateQueue() {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('queues.breadcrumb_create')} />

            <div className="mx-auto max-w-xl space-y-6 p-6">
                <h1 className="text-xl font-semibold">{t('queues.breadcrumb_create')}</h1>

                <Form
                    {...QueueController.store.form()}
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">{t('queues.name_label')}</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    autoFocus
                                    placeholder={t('queues.name_placeholder')}
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="flex gap-3 pt-2">
                                <Button type="submit" disabled={processing}>
                                    {t('queues.submit_create')}
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={QueueController.index.url()}>
                                        {t('queues.cancel')}
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

CreateQueue.layout = {
    breadcrumbs: [
        {
            title: 'queues.breadcrumb_index',
            href: QueueController.index.url(),
        },
        {
            title: 'queues.breadcrumb_create',
            href: QueueController.create.url(),
        },
    ],
};
