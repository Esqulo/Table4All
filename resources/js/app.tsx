import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import '@/i18n';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import CustomerLayout from '@/layouts/customer-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { trackInAppVisits } from '@/lib/history';

const appName = import.meta.env.VITE_APP_NAME || 'Table4All';

// Before the first page renders, so its own visit is counted (see goBack()).
trackInAppVisits();

createInertiaApp({
    // Pages without a title (the home page) show the brand name as written in the logo.
    title: (title) => (title ? `${title} - ${appName}` : 'Table 4 all'),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            case name === 'contact':
            case name.startsWith('menu/'):
            case name.startsWith('profile/'):
            case name === 'restaurant/menus/print':
            case name.startsWith('waiter/'):
            case name === 'error': // picks AppLayout (signed in) or the public bar itself
                return null;
            case name.startsWith('table/'):
                return CustomerLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#22b573',
    },
});

// This will set light / dark mode on load...
initializeTheme();
