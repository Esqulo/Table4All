import { useTranslation } from 'react-i18next';
import { getLocale, setLocale } from '@/i18n';
import { Button } from '@/components/ui/button';

export function LanguageToggle() {
    const { i18n } = useTranslation();
    const current = i18n.language as 'pt_BR' | 'en';
    const next = current === 'pt_BR' ? 'en' : 'pt_BR';

    return (
        <Button
            variant="ghost"
            size="sm"
            onClick={() => setLocale(next)}
            aria-label={`Switch to ${next === 'en' ? 'English' : 'Portuguese'}`}
            className="text-xs font-medium"
        >
            {current === 'pt_BR' ? 'EN' : 'PT'}
        </Button>
    );
}
