import { Menu } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { DarkModeToggle } from '@/components/dark-mode-toggle';
import { LanguageToggle } from '@/components/language-toggle';
import { SidebarTrigger, useSidebar } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { t } = useTranslation();
    const { setOpenMobile } = useSidebar();

    return (
        <header className="flex h-16 shrink-0 items-center gap-2 border-b border-dashed border-border px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex min-w-0 items-center gap-2">
                {/* Phones: a solid, labelled button, so the menu is easy to spot and to hit. */}
                <button
                    type="button"
                    onClick={() => setOpenMobile(true)}
                    className="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 pr-3.5 pl-3 text-sm font-semibold text-primary shadow-xs transition-colors hover:bg-primary/15 active:scale-95 md:hidden"
                    aria-label={t('nav.open_menu')}
                >
                    <Menu className="size-5" />
                    {t('nav.menu')}
                </button>
                <SidebarTrigger className="-ml-1 hidden md:inline-flex" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <div className="ml-auto flex shrink-0 items-center gap-1">
                <LanguageToggle />
                <DarkModeToggle />
            </div>
        </header>
    );
}
