import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Newspaper, Search } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import SocialFeedController from '@/actions/App/Http/Controllers/SocialFeedController';
import AppLogoWordmark from '@/components/app-logo-wordmark';
import { Button } from '@/components/ui/button';
import { goBack } from '@/lib/history';
import { feedSearchUrl } from '@/lib/social';
import { home, login } from '@/routes';

/** Signed in, on the public pages (where auth.user is null for guests). */
function useSignedIn(): boolean {
    return Boolean(usePage().props.auth?.user);
}

/** Search and feed shortcuts, or "Entrar" for visitors. */
export function SocialNavActions() {
    const { t } = useTranslation();

    if (!useSignedIn()) {
        return (
            <Button size="sm" asChild>
                <Link href={login.url()}>{t('social.nav_login')}</Link>
            </Button>
        );
    }

    return (
        <div className="flex items-center gap-1">
            <Link
                href={feedSearchUrl()}
                aria-label={t('social.nav_search')}
                title={t('social.nav_search')}
                className="flex size-9 items-center justify-center rounded-full hover:bg-muted"
            >
                <Search className="h-5 w-5" />
            </Link>
            <Link
                href={SocialFeedController.index.url()}
                aria-label={t('social.nav_feed')}
                title={t('social.nav_feed')}
                className="flex size-9 items-center justify-center rounded-full hover:bg-muted"
            >
                <Newspaper className="h-5 w-5" />
            </Link>
        </div>
    );
}

/** Top bar of the public profile: the way back to the feed (or the site) and to search. */
export function SocialTopBar() {
    const { t } = useTranslation();
    const signedIn = useSignedIn();
    const start = signedIn ? SocialFeedController.index.url() : home.url();

    return (
        <nav
            aria-label={t('social.nav_label')}
            className="sticky top-0 z-20 border-b bg-background/95 backdrop-blur"
        >
            <div className="mx-auto flex h-14 max-w-3xl items-center justify-between gap-3 px-4">
                <div className="flex items-center gap-1">
                    {/* Previous page of the app, or the feed/site when the profile was opened directly. */}
                    <button
                        type="button"
                        onClick={() => goBack(start)}
                        aria-label={t('social.nav_back')}
                        title={t('social.nav_back')}
                        className="-ml-2 flex size-9 items-center justify-center rounded-full hover:bg-muted"
                    >
                        <ArrowLeft className="h-5 w-5" />
                    </button>
                    <Link
                        href={start}
                        aria-label={
                            signedIn
                                ? t('social.nav_feed')
                                : t('social.nav_home')
                        }
                    >
                        <AppLogoWordmark className="h-5" />
                    </Link>
                </div>
                <SocialNavActions />
            </div>
        </nav>
    );
}
