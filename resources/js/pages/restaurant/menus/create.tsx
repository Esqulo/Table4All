import { Form, Head, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import MenuController from '@/actions/App/Http/Controllers/Restaurant/MenuController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function CreateMenu() {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('menus.breadcrumb_create')} />

            <div className="mx-auto max-w-xl space-y-6 p-6">
                <h1 className="text-xl font-semibold">{t('menus.breadcrumb_create')}</h1>

                <Form {...MenuController.store.form()} className="space-y-5">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">{t('menus.name_label')}</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    autoFocus
                                    placeholder={t('menus.name_placeholder')}
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="flex gap-3 pt-2">
                                <Button type="submit" disabled={processing}>
                                    {t('menus.submit_create')}
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={MenuController.index.url()}>
                                        {t('menus.cancel')}
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

CreateMenu.layout = {
    breadcrumbs: [
        {
            title: 'menus.breadcrumb_index',
            href: MenuController.index.url(),
        },
        {
            title: 'menus.breadcrumb_create',
            href: MenuController.create.url(),
        },
    ],
};
