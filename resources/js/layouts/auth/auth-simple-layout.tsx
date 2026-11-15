import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import AppLogoIcon from '@/components/app-logo-icon';
import AppLogoWordmark from '@/components/app-logo-wordmark';
import { DarkModeToggle } from '@/components/dark-mode-toggle';
import { LanguageToggle } from '@/components/language-toggle';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

/**
 * Branded layout for every auth screen (login, register, password reset, 2FA…).
 * The `.t4a-auth` scope in app.css maps the shadcn tokens to the welcome-page
 * palette, so the existing forms pick up the identity without changes.
 */
export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { t } = useTranslation();

    return (
        <div className="t4a-welcome t4a-auth grid min-h-svh font-sans antialiased lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
            <div className="flex min-h-svh flex-col px-4 py-5 sm:px-8">
                <header className="flex items-center justify-between gap-4">
                    <Link
                        href={home()}
                        aria-label="Table4All"
                        className="flex items-center gap-2.5 rounded-md"
                    >
                        <AppLogoIcon className="h-10 w-7" />
                        <AppLogoWordmark className="hidden h-[1.35rem] min-[360px]:block sm:h-6" />
                    </Link>
                    <div className="flex items-center gap-1">
                        <LanguageToggle />
                        <DarkModeToggle />
                    </div>
                </header>

                <main className="flex flex-1 items-center justify-center py-12">
                    <div className="w-full max-w-[26rem]">
                        <h1 className="font-display text-[clamp(2.4rem,6vw,3.25rem)] leading-[0.95] font-extrabold tracking-[-0.005em] text-balance">
                            {t(title ?? '')}
                        </h1>
                        {description ? (
                            <p className="mt-3 text-[16px] leading-relaxed text-[var(--w-ink-soft)]">
                                {t(description)}
                            </p>
                        ) : null}
                        <div className="mt-9">{children}</div>
                    </div>
                </main>
            </div>

            {/* Brand panel: the green tiled wall of a boteco, as on the welcome page */}
            <aside
                className="hidden bg-[var(--w-green-ground)] text-[var(--w-on-green)] lg:sticky lg:top-0 lg:flex lg:h-svh lg:flex-col lg:justify-end"
                style={{
                    backgroundImage:
                        'linear-gradient(color-mix(in oklch, var(--w-on-green) 7%, transparent) 1px, transparent 1px), linear-gradient(90deg, color-mix(in oklch, var(--w-on-green) 7%, transparent) 1px, transparent 1px)',
                    backgroundSize: '56px 56px',
                }}
            >
                <div className="p-12 xl:p-16">
                    <p className="max-w-[12ch] font-display text-[clamp(3rem,5vw,5rem)] leading-[0.9] font-extrabold text-balance uppercase">
                        {t('welcome.hero.title')}
                    </p>
                    <p className="mt-6 max-w-[30rem] text-lg leading-relaxed text-[var(--w-on-green-soft)]">
                        {t('auth.panel.lead')}
                    </p>
                </div>
            </aside>
        </div>
    );
}
