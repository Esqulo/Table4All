import { Form, Head, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import TableController from '@/actions/App/Http/Controllers/Restaurant/TableController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { RestaurantTable } from '@/types';

type Props = {
    table: RestaurantTable;
};

export default function EditTable({ table }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('tables.breadcrumb_edit')} />

            <div className="mx-auto max-w-md space-y-6 p-6">
                <h1 className="text-xl font-semibold">{t('tables.breadcrumb_edit')}</h1>

                <Form
                    {...TableController.update.form({ table: table.id })}
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="title">{t('tables.title_label')}</Label>
                                <Input
                                    id="title"
                                    name="title"
                                    required
                                    autoFocus
                                    defaultValue={table.title}
                                    placeholder={t('tables.title_placeholder')}
                                />
                                <InputError message={errors.title} />
                            </div>

                            <div className="flex gap-3 pt-2">
                                <Button type="submit" disabled={processing}>
                                    {t('tables.submit_update')}
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

EditTable.layout = {
    breadcrumbs: [
        { title: 'tables.breadcrumb_index', href: TableController.index.url() },
        { title: 'tables.breadcrumb_edit', href: '#' },
    ],
};
