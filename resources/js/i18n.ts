import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import en from '@/locales/en.json';
import ptBR from '@/locales/pt_BR.json';

const stored = typeof window !== 'undefined' ? localStorage.getItem('locale') : null;
const defaultLng = (stored as 'pt_BR' | 'en' | null) ?? 'pt_BR';

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

export function setLocale(lng: 'pt_BR' | 'en'): void {
    localStorage.setItem('locale', lng);
    void i18n.changeLanguage(lng);
}

export function getLocale(): 'pt_BR' | 'en' {
    return (i18n.language as 'pt_BR' | 'en') ?? 'pt_BR';
}

export default i18n;
