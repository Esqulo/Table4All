import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { SocialTopBar } from '@/components/social/social-nav';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { goBack } from '@/lib/history';
import { dashboard, home } from '@/routes';

type Props = {
    status: 403 | 404 | 429 | 500 | 503;
    /** Already translated by the server ("Página não encontrada"). */
    title: string;
};

/**
 * HTTP errors, shown inside the app: signed-in users keep the sidebar, visitors
 * get the public top bar. Rendered by App\Http\ErrorPage.
 */
export default function ErrorPage({ status, title }: Props) {
    const { t } = useTranslation();
    // auth may be missing when the error happened before the session started.
    const signedIn = Boolean(usePage().props.auth?.user);
    const start = signedIn ? dashboard.url() : home.url();

    const content = (
        <div className="flex flex-1 items-center justify-center p-6">
            <div className="max-w-md space-y-5 text-center">
                <p
                    className="font-display text-7xl leading-none font-extrabold text-primary"
                    aria-hidden
                >
                    {status}
                </p>
                <div className="space-y-2">
                    <h1 className="font-display text-3xl leading-none font-extrabold text-balance">
                        {title}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t(`errors.description_${status}`)}
                    </p>
                </div>
                <div className="flex flex-wrap justify-center gap-2">
                    <Button variant="outline" onClick={() => goBack(start)}>
                        <ArrowLeft className="h-4 w-4" />
                        {t('errors.back')}
                    </Button>
                    <Button asChild>
                        <Link href={start}>
                            {signedIn
                                ? t('errors.go_start')
                                : t('errors.go_home')}
                        </Link>
                    </Button>
                </div>
            </div>
        </div>
    );

    return (
        <>
            <Head title={title} />
            {signedIn ? (
                <AppLayout
                    breadcrumbs={[{ title: 'errors.breadcrumb', href: '#' }]}
                >
                    {content}
                </AppLayout>
            ) : (
                <div className="flex min-h-screen flex-col bg-background">
                    <SocialTopBar />
                    {content}
                </div>
            )}
        </>
    );
}
