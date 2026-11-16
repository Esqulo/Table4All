import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import en from '@/locales/en.json';
import ptBR from '@/locales/pt_BR.json';

type Locale = 'pt_BR' | 'en';

const SUPPORTED: Locale[] = ['pt_BR', 'en'];

// localStorage can throw (old browsers, private mode, blocked storage).
function readStoredLocale(): Locale | null {
    try {
        const value = typeof window !== 'undefined' ? window.localStorage.getItem('locale') : null;

        return SUPPORTED.includes(value as Locale) ? (value as Locale) : null;
    } catch {
        return null;
    }
}

const defaultLng: Locale = readStoredLocale() ?? 'pt_BR';

i18n.use(initReactI18next).init({
    resources: {
        pt_BR: { translation: ptBR },
        en: { translation: en },
    },
    lng: defaultLng,
    fallbackLng: 'pt_BR',
    interpolation: {
        escapeValue: false,
    },
});

/**
 * Mirrors the interface language into the `locale` cookie, which the server
 * reads (App\Http\Middleware\SetLocale) so that validation errors, login
 * messages, emails and error pages come back in the same language.
 */
function syncServerLocale(lng: string): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.cookie = `locale=${lng}; path=/; max-age=31536000; SameSite=Lax`;
    document.documentElement.lang = lng.replace('_', '-');
}

syncServerLocale(defaultLng);

export function setLocale(lng: Locale): void {
    try {
        localStorage.setItem('locale', lng);
    } catch {
        // Not persisted on this device; the cookie still carries the choice.
    }

    syncServerLocale(lng);
    void i18n.changeLanguage(lng);
}

export function getLocale(): Locale {
    return (i18n.language as Locale) ?? 'pt_BR';
}

export default i18n;
