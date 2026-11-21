import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import OverviewController from '@/actions/App/Http/Controllers/Admin/OverviewController';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';

/** Shown once, right after the authenticator is set up. */
export default function AdminRecoveryCodes({ codes }: { codes: string[] }) {
    const { t } = useTranslation();
    const [copiedText, copy] = useClipboard();
    const all = codes.join('\n');

    setLayoutProps({
        title: 'admin_auth.recovery_codes_title',
        description: 'admin_auth.recovery_codes_description',
    });

    return (
        <>
            <Head title={t('admin_auth.recovery_head')} />

            <div className="space-y-4">
                <ul className="grid grid-cols-2 gap-2 rounded-xl border bg-muted p-4 font-mono text-sm">
                    {codes.map((code) => (
                        <li key={code}>{code}</li>
                    ))}
                </ul>
                <Button
                    type="button"
                    variant="outline"
                    className="w-full"
                    onClick={() => copy(all)}
                >
                    {copiedText === all ? (
                        <Check className="size-4" />
                    ) : (
                        <Copy className="size-4" />
                    )}
                    {copiedText === all
                        ? t('admin_auth.copied')
                        : t('admin_auth.copy')}
                </Button>
                <Button asChild className="w-full">
                    <Link href={OverviewController.index.url()}>
                        {t('admin_auth.recovery_done')}
                    </Link>
                </Button>
            </div>
        </>
    );
}
