import { createInertiaApp } from '@inertiajs/react';
import { CookieNotice } from '@/components/cookie-notice';
import { SwitchLocaleAfterHydration } from '@/components/switch-locale-after-hydration';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { adoptServerLocale } from '@/i18n';
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
    layout: (name, page) => {
        switch (true) {
            // Restaurant profiles open inside the app for signed-in users (same
            // sidebar as the feed); visitors get the page's own public top bar.
            case name.startsWith('profile/'):
                // auth.user is null for guests on public pages.
                return (page.props as { auth?: { user?: unknown } }).auth?.user
                    ? AppLayout
                    : null;
            case name === 'welcome':
            case name === 'contact':
            case name === 'partner': // sales page for venue owners (/parceiro)
            case name.startsWith('legal/'): // privacy policy and terms (public site chrome)
            case name.startsWith('menu/'):
            case name === 'restaurant/menus/print':
            case name.startsWith('waiter/'):
            case name === 'error': // picks AppLayout (signed in) or the public bar itself
                return null;
            case name.startsWith('table/'):
                return CustomerLayout;
            case name.startsWith('auth/'):
            case name.startsWith('admin/auth/'): // the admins' own sign-in screens
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app, { page }) {
        // Same language on the server and in the hydrating browser (see adoptServerLocale).
        const preferred = adoptServerLocale(page.props.locale);

        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
                <CookieNotice />
                {preferred && <SwitchLocaleAfterHydration to={preferred} />}
            </TooltipProvider>
        );
    },
    progress: {
        color: '#22b573',
    },
});

// This will set light / dark mode on load...
initializeTheme();
