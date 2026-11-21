import { Cookie } from 'lucide-react';
import { useState, useSyncExternalStore } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { privacy } from '@/routes';

/** Bump when the cookies listed in the privacy policy change, so everyone sees the notice again. */
const NOTICE_KEY = 't4a.cookie-notice';
const NOTICE_VERSION = '1';

function dismissed(): boolean {
    try {
        return window.localStorage.getItem(NOTICE_KEY) === NOTICE_VERSION;
    } catch {
        return false;
    }
}

// Nothing to subscribe to: the value only changes through this component's own button.
const subscribe = () => () => {};

/**
 * Tells visitors which cookies the app uses. They are all strictly necessary (session,
 * CSRF protection, remembered sign-in, language, theme, sidebar) and there is no tracking
 * or advertising, so this informs rather than asks for consent (LGPD Art. 7 V and IX, 9).
 * The list lives in the privacy policy, section "cookies".
 *
 * Hidden in the server render (it can't know whether it was dismissed), then the browser
 * reads its own storage while hydrating.
 */
export function CookieNotice() {
    const { t } = useTranslation();
    const wasDismissed = useSyncExternalStore(subscribe, dismissed, () => true);
    const [closed, setClosed] = useState(false);

    if (wasDismissed || closed) {
        return null;
    }

    const close = () => {
        try {
            window.localStorage.setItem(NOTICE_KEY, NOTICE_VERSION);
        } catch {
            // Storage blocked (private mode): the notice just shows again next visit.
        }

        setClosed(true);
    };

    return (
        <div
            role="region"
            aria-label={t('cookies.label')}
            className="fixed inset-x-0 bottom-0 z-50 p-3 sm:p-4 print:hidden"
        >
            <div className="mx-auto flex max-w-3xl flex-col gap-3 rounded-2xl border bg-background p-4 text-sm text-foreground shadow-lg sm:flex-row sm:items-center sm:gap-4">
                <Cookie
                    className="hidden size-5 shrink-0 text-muted-foreground sm:block"
                    aria-hidden="true"
                />
                <p className="flex-1 leading-snug text-muted-foreground">
                    {t('cookies.text')}{' '}
                    <a
                        href={`${privacy.url()}#cookies`}
                        className="font-medium text-foreground underline underline-offset-4"
                    >
                        {t('cookies.learn_more')}
                    </a>
                </p>
                <Button
                    size="sm"
                    onClick={close}
                    className="self-end sm:self-auto"
                >
                    {t('cookies.accept')}
                </Button>
            </div>
        </div>
    );
}
