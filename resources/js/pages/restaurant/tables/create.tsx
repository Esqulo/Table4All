import { Form, Head, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import TableController from '@/actions/App/Http/Controllers/Restaurant/TableController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function CreateTable() {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('tables.breadcrumb_create')} />

            <div className="mx-auto max-w-md space-y-6 p-6">
                <h1 className="text-xl font-semibold">{t('tables.breadcrumb_create')}</h1>

                <Form {...TableController.store.form()} className="space-y-5">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="title">{t('tables.title_label')}</Label>
                                <Input
                                    id="title"
                                    name="title"
                                    required
                                    autoFocus
                                    placeholder={t('tables.title_placeholder')}
                                />
                                <InputError message={errors.title} />
                            </div>

                            <div className="flex gap-3 pt-2">
                                <Button type="submit" disabled={processing}>
                                    {t('tables.submit_create')}
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={TableController.index.url()}>
                                        {t('tables.cancel')}
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

CreateTable.layout = {
    breadcrumbs: [
        { title: 'tables.breadcrumb_index', href: TableController.index.url() },
        { title: 'tables.breadcrumb_create', href: TableController.create.url() },
    ],
};
