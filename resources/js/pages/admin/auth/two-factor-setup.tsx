import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import TwoFactorSetupController from '@/actions/App/Http/Controllers/Admin/Auth/TwoFactorSetupController';
import OtpField from '@/components/admin/otp-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';
import { useClipboard } from '@/hooks/use-clipboard';

/**
 * Setting up the authenticator app (required for admins): scan the QR code
 * or type the key, then confirm with the first code.
 */
export default function AdminTwoFactorSetup({
    qrCodeSvg,
    secret,
}: {
    qrCodeSvg: string;
    secret: string;
}) {
    const { t } = useTranslation();
    const { resolvedAppearance } = useAppearance();
    const [copiedText, copy] = useClipboard();
    const [code, setCode] = useState('');
    const CopyIcon = copiedText === secret ? Check : Copy;

    setLayoutProps({
        title: 'admin_auth.setup_title',
        description: 'admin_auth.setup_description',
    });

    return (
        <>
            <Head title={t('admin_auth.setup_head')} />

            <div className="space-y-6">
                <ol className="list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
                    <li>{t('admin_auth.setup_step_1')}</li>
                    <li>{t('admin_auth.setup_step_2')}</li>
                    <li>{t('admin_auth.setup_step_3')}</li>
                </ol>

                {/* Generated on the server by Fortify from the admin's own secret. */}
                <div
                    className="mx-auto aspect-square w-56 rounded-lg border bg-white p-3 [&_svg]:size-full"
                    dangerouslySetInnerHTML={{ __html: qrCodeSvg }}
                    style={{
                        filter:
                            resolvedAppearance === 'dark'
                                ? 'invert(1) brightness(1.5)'
                                : undefined,
                    }}
                />

                <div className="space-y-1">
                    <p className="text-xs text-muted-foreground">
                        {t('admin_auth.setup_key')}
                    </p>
                    <div className="flex items-stretch overflow-hidden rounded-xl border">
                        <input
                            readOnly
                            value={secret}
                            className="w-full bg-background p-3 font-mono text-sm outline-none"
                        />
                        <button
                            type="button"
                            onClick={() => copy(secret)}
                            aria-label={
                                copiedText === secret
                                    ? t('admin_auth.copied')
                                    : t('admin_auth.copy')
                            }
                            className="border-l px-3 hover:bg-muted"
                        >
                            <CopyIcon className="size-4" />
                        </button>
                    </div>
                </div>

                <Form
                    {...TwoFactorSetupController.store.form()}
                    className="space-y-4"
                    resetOnError
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="flex flex-col items-center gap-3">
                                <OtpField
                                    value={code}
                                    onChange={setCode}
                                    disabled={processing}
                                />
                                <InputError message={errors.code} />
                            </div>
                            <Button
                                type="submit"
                                className="w-full"
                                disabled={processing || code.length < 6}
                            >
                                {t('admin_auth.confirm')}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
