import { usePage } from '@inertiajs/react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { CloseSidebarOnNavigate } from '@/components/close-sidebar-on-navigate';
import { CustomerBottomNav } from '@/components/customer-bottom-nav';
import { cn } from '@/lib/utils';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const { auth } = usePage().props;
    // Customers get a bottom bar on phones; the sidebar stays for every screen.
    const bottomNav = auth.user?.account_type === 'customer';

    return (
        <AppShell variant="sidebar">
            <CloseSidebarOnNavigate />
            <AppSidebar />
            <AppContent
                variant="sidebar"
                className={cn(
                    'overflow-x-clip',
                    // Room for the bottom bar; sticky footers sit on top of it with bottom-(--bottom-nav-h).
                    bottomNav &&
                        'pb-(--bottom-nav-h,0px) max-md:[--bottom-nav-h:calc(4rem+env(safe-area-inset-bottom))] max-md:[--bottom-safe-area:0px]',
                )}
            >
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
            {bottomNav && <CustomerBottomNav />}
        </AppShell>
    );
}
