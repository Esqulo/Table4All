import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { setLocale } from '@/i18n';
import { cn } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    const { t, i18n } = useTranslation();
    const currentLocale = i18n.language as 'pt_BR' | 'en';

    return (
        <>
            <Head title={t('settings.appearance.head')} />

            <h1 className="sr-only">{t('settings.appearance.head')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.appearance.section_title')}
                    description={t('settings.appearance.section_description')}
                />
                <AppearanceTabs />

                <div className="pt-2">
                    <Heading variant="small" title={t('language.toggle_label')} />
                    <div className="mt-3 inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800">
                        {(['pt_BR', 'en'] as const).map((locale) => (
                            <button
                                key={locale}
                                onClick={() => setLocale(locale)}
                                className={cn(
                                    'rounded-md px-4 py-1.5 text-sm transition-colors',
                                    currentLocale === locale
                                        ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                                        : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                                )}
                            >
                                {t(`language.${locale}`)}
                            </button>
                        ))}
                    </div>
                </div>
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'settings.appearance.title',
            href: editAppearance(),
        },
    ],
};
