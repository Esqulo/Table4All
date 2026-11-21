import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import LoginController from '@/actions/App/Http/Controllers/Admin/Auth/LoginController';
import OtpField from '@/components/admin/otp-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/** Step 2 of the admin sign-in: the authenticator app's code, or a recovery code. */
export default function AdminTwoFactor() {
    const { t } = useTranslation();
    const [recovery, setRecovery] = useState(false);
    const [code, setCode] = useState('');

    setLayoutProps({
        title: recovery ? 'admin_auth.recovery_title' : 'admin_auth.code_title',
        description: recovery
            ? 'admin_auth.recovery_description'
            : 'admin_auth.code_description',
    });

    return (
        <>
            <Head title={t('admin_auth.code_head')} />

            <Form
                {...LoginController.verifyCode.form()}
                className="space-y-4"
                resetOnError
            >
                {({ errors, processing, clearErrors }) => (
                    <>
                        {recovery ? (
                            <>
                                <Input
                                    name="recovery_code"
                                    placeholder={t(
                                        'admin_auth.recovery_placeholder',
                                    )}
                                    autoComplete="off"
                                    autoFocus
                                    required
                                />
                                <InputError message={errors.recovery_code} />
                            </>
                        ) : (
                            <div className="flex flex-col items-center gap-3">
                                <OtpField
                                    value={code}
                                    onChange={setCode}
                                    disabled={processing}
                                />
                                <InputError message={errors.code} />
                            </div>
                        )}

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                        >
                            {t('admin_auth.sign_in')}
                        </Button>

                        <div className="text-center text-sm text-muted-foreground">
                            <span>{t('admin_auth.or')} </span>
                            <button
                                type="button"
                                className="cursor-pointer text-foreground underline underline-offset-4"
                                onClick={() => {
                                    setRecovery(!recovery);
                                    setCode('');
                                    clearErrors();
                                }}
                            >
                                {recovery
                                    ? t('admin_auth.use_code')
                                    : t('admin_auth.use_recovery')}
                            </button>
                        </div>
                        <p className="text-center text-sm">
                            <Link
                                href={LoginController.show.url()}
                                className="text-muted-foreground underline-offset-4 hover:underline"
                            >
                                {t('admin_auth.back_to_login')}
                            </Link>
                        </p>
                    </>
                )}
            </Form>
        </>
    );
}
