import { useEffect } from 'react';
import { setLocale } from '@/i18n';

/**
 * This browser chose another language than the response's (see
 * adoptServerLocale): switch once hydrated. Kept out of app.tsx on purpose: a
 * component there makes it a Fast Refresh boundary that imports itself, and
 * after an edit Vite runs the app twice (two hydrations of the same HTML).
 */
export function SwitchLocaleAfterHydration({ to }: { to: 'pt_BR' | 'en' }) {
    useEffect(() => setLocale(to), [to]);

    return null;
}
