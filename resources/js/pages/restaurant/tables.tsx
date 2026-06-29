import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { tables } from '@/routes/restaurant';

export default function Tables() {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('nav.tables')} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <p className="text-muted-foreground text-sm">{t('nav.tables')}</p>
            </div>
        </>
    );
}

Tables.layout = {
    breadcrumbs: [
        {
            title: 'nav.tables',
            href: tables(),
        },
    ],
};
