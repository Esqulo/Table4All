import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import LoginController from '@/actions/App/Http/Controllers/Admin/Auth/LoginController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

/** Step 1 of the admin sign-in: e-mail and password (the authenticator code comes next). */
export default function AdminLogin() {
    const { t } = useTranslation();

    setLayoutProps({
        title: 'admin_auth.login_title',
        description: 'admin_auth.login_description',
    });

    return (
        <>
            <Head title={t('admin_auth.login_head')} />

            <Form
                {...LoginController.store.form()}
                resetOnSuccess={['password']}
                resetOnError={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="email">
                                {t('admin_auth.email')}
                            </Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                autoComplete="username"
                            />
                            <InputError message={errors.email} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password">
                                {t('admin_auth.password')}
                            </Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                autoComplete="current-password"
                            />
                            <InputError message={errors.password} />
                        </div>
                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing ? (
                                <Spinner />
                            ) : (
                                <ShieldCheck className="size-4" />
                            )}
                            {t('admin_auth.submit')}
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}
