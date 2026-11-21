import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import AppLogoIcon from '@/components/app-logo-icon';
import AppLogoWordmark from '@/components/app-logo-wordmark';
import { DarkModeToggle } from '@/components/dark-mode-toggle';
import { LanguageToggle } from '@/components/language-toggle';
import {
    contact,
    dashboard,
    home,
    login,
    privacy,
    register,
    terms,
} from '@/routes';

/** Shared buttons, header and footer for the public pages (welcome, contact, legal). */

export const btnPrimary =
    'inline-flex h-12 items-center justify-center gap-2 rounded-full bg-[var(--w-btn-bg)] px-7 text-[15px] font-semibold text-[var(--w-btn-fg)] shadow-[0_1px_2px_oklch(0.2_0.03_155/0.25),0_8px_18px_-8px_oklch(0.3_0.08_157/0.55)] transition-colors duration-200 hover:bg-[var(--w-btn-bg-hover)]';

export const btnSecondary =
    'inline-flex h-12 items-center justify-center gap-2 rounded-full border border-[color-mix(in_oklch,var(--w-ink)_28%,transparent)] px-7 text-[15px] font-semibold text-[var(--w-ink)] transition-colors duration-200 hover:border-[var(--w-ink)] hover:bg-[color-mix(in_oklch,var(--w-ink)_5%,transparent)]';

export function SitePage({ children }: { children: ReactNode }) {
    return (
        <div className="t4a-welcome flex min-h-svh flex-col overflow-x-clip font-sans antialiased">
            <SiteHeader />
            <main className="flex-1">{children}</main>
            <SiteFooter />
        </div>
    );
}

function SiteHeader() {
    const { auth } = usePage().props;
    const { t } = useTranslation();
    const homeUrl = home.url();
    const navLink = 'hover:text-[var(--w-green)]';

    return (
        <header className="mx-auto flex w-full max-w-[76rem] items-center justify-between gap-4 px-4 py-5 sm:px-6 lg:px-8">
            <Link
                href={home()}
                aria-label="Table4All"
                className="flex items-center gap-2.5 rounded-md"
            >
                <AppLogoIcon className="h-10 w-7" />
                <AppLogoWordmark className="hidden h-[1.35rem] min-[360px]:block sm:h-6" />
            </Link>

            <nav
                aria-label={t('welcome.nav.main')}
                className="hidden items-center gap-7 text-[15px] font-medium md:flex"
            >
                <a href={`${homeUrl}#como-funciona`} className={navLink}>
                    {t('welcome.nav.how')}
                </a>
                <a href={`${homeUrl}#bares`} className={navLink}>
                    {t('welcome.nav.owners')}
                </a>
                <a href={`${homeUrl}#clientes`} className={navLink}>
                    {t('welcome.nav.guests')}
                </a>
            </nav>

            <div className="flex items-center gap-1 sm:gap-2">
                <LanguageToggle />
                <DarkModeToggle />
                {auth.user ? (
                    <Link
                        href={dashboard()}
                        className="ml-1 hidden rounded-full px-4 py-2 text-[15px] font-semibold hover:text-[var(--w-green)] sm:inline-flex"
                    >
                        {t('welcome.cta.dashboard')}
                    </Link>
                ) : (
                    <>
                        <Link
                            href={login()}
                            className="ml-1 rounded-full px-3 py-2 text-[15px] font-semibold hover:text-[var(--w-green)] sm:px-4"
                        >
                            {t('welcome.cta.login')}
                        </Link>
                        <Link
                            href={register()}
                            className="hidden rounded-full bg-[var(--w-btn-bg)] px-4 py-2 text-[15px] font-semibold text-[var(--w-btn-fg)] transition-colors hover:bg-[var(--w-btn-bg-hover)] sm:inline-flex"
                        >
                            {t('welcome.cta.register')}
                        </Link>
                    </>
                )}
            </div>
        </header>
    );
}

const footerLink =
    'font-medium text-[var(--w-ink)] underline decoration-[var(--w-rule)] hover:text-[var(--w-green)] hover:decoration-[var(--w-green)]';

function SiteFooter() {
    const { t } = useTranslation();
    const year = new Date().getFullYear();

    return (
        <footer className="border-t border-[var(--w-rule)]">
            <div className="mx-auto flex max-w-[76rem] flex-col gap-3 px-4 py-8 text-sm text-[var(--w-ink-soft)] sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                <span className="flex items-center gap-2 font-medium text-[var(--w-ink)]">
                    <AppLogoIcon className="h-6 w-4" />
                    {t('welcome.footer.rights', { year })}
                </span>
                <span className="flex flex-wrap items-center gap-x-5 gap-y-2">
                    <span>{t('welcome.footer.made')}</span>
                    <nav
                        aria-label={t('welcome.footer.nav')}
                        className="flex flex-wrap items-center gap-x-5 gap-y-2"
                    >
                        <Link href={contact()} className={footerLink}>
                            {t('welcome.footer.contact')}
                        </Link>
                        <Link href={privacy()} className={footerLink}>
                            {t('welcome.footer.privacy')}
                        </Link>
                        <Link href={terms()} className={footerLink}>
                            {t('welcome.footer.terms')}
                        </Link>
                        <a
                            href={`${privacy.url()}#cookies`}
                            className={footerLink}
                        >
                            {t('welcome.footer.cookies')}
                        </a>
                    </nav>
                </span>
            </div>
        </footer>
    );
}
