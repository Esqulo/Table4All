import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import InvitationController from '@/actions/App/Http/Controllers/Admin/Auth/InvitationController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/** Accepting an admin invitation: name and password, then the authenticator setup. */
export default function AdminAcceptInvitation({
    token,
    email,
    valid,
    passwordRules,
}: {
    token: string;
    email: string | null;
    valid: boolean;
    passwordRules: string;
}) {
    const { t } = useTranslation();

    setLayoutProps({
        title: 'admin_auth.invitation_title',
        description: 'admin_auth.invitation_description',
    });

    return (
        <>
            <Head title={t('admin_auth.invitation_head')} />

            {!valid ? (
                <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                    {t('admin_auth.invitation_invalid')}
                </p>
            ) : (
                <Form
                    {...InvitationController.store.form(token)}
                    resetOnError={['password', 'password_confirmation']}
                    className="flex flex-col gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('admin_auth.email')}
                                </Label>
                                <Input
                                    id="email"
                                    value={email ?? ''}
                                    readOnly
                                    autoComplete="username"
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    {t('admin_auth.name')}
                                </Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    autoFocus
                                    maxLength={255}
                                    autoComplete="name"
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    {t('admin_auth.password')}
                                </Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete="new-password"
                                    passwordrules={passwordRules}
                                />
                                <InputError message={errors.password} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    {t('admin_auth.password_confirmation')}
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    required
                                    autoComplete="new-password"
                                />
                            </div>
                            <Button
                                type="submit"
                                className="w-full"
                                disabled={processing}
                            >
                                {t('admin_auth.create_account')}
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </>
    );
}
