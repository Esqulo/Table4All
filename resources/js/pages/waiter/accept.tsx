import { Head, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import WaiterInvitationController from '@/actions/App/Http/Controllers/WaiterInvitationController';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { login } from '@/routes';

type Props = {
    token: string;
    email: string;
    restaurant: { id: number; name: string };
    email_taken: boolean;
};

export default function WaiterAccept({ token, email, restaurant, email_taken }: Props) {
    const { t } = useTranslation();
    const form = useForm({ name: '', password: '', password_confirmation: '' });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post(WaiterInvitationController.store.url({ token }));
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-background px-4">
            <Head title={t('waiter_accept.head_title', { restaurant: restaurant.name })} />

            <div className="w-full max-w-sm space-y-6">
                <div className="flex flex-col items-center gap-3 text-center">
                    <AppLogoIcon className="h-14 w-12" />
                    <h1 className="text-xl font-semibold">
                        {t('waiter_accept.heading')} <span className="text-primary">{restaurant.name}</span>
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('waiter_accept.intro')}
                    </p>
                    <p className="rounded-md bg-muted px-3 py-1.5 text-sm font-medium">{email}</p>
                </div>

                {email_taken ? (
                    <div className="rounded-lg border border-border bg-card p-5 text-center space-y-3">
                        <p className="text-sm text-muted-foreground">
                            {t('waiter_accept.email_taken')}
                        </p>
                        <Button variant="outline" asChild className="w-full">
                            <a href={login.url()}>{t('waiter_accept.go_to_login')}</a>
                        </Button>
                    </div>
                ) : (
                    <form
                        onSubmit={handleSubmit}
                        className="rounded-lg border border-border bg-card p-5 shadow-xs space-y-4"
                    >
                        <div className="space-y-1">
                            <Label htmlFor="name">{t('waiter_accept.name_label')}</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder={t('waiter_accept.name_placeholder')}
                                autoComplete="name"
                                autoFocus
                            />
                            {form.errors.name && (
                                <p className="text-xs text-destructive">{form.errors.name}</p>
                            )}
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="password">{t('waiter_accept.password_label')}</Label>
                            <Input
                                id="password"
                                type="password"
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                                placeholder={t('waiter_accept.password_placeholder')}
                                autoComplete="new-password"
                            />
                            {form.errors.password && (
                                <p className="text-xs text-destructive">{form.errors.password}</p>
                            )}
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="password_confirmation">{t('waiter_accept.confirm_label')}</Label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                value={form.data.password_confirmation}
                                onChange={(e) => form.setData('password_confirmation', e.target.value)}
                                placeholder={t('waiter_accept.confirm_placeholder')}
                                autoComplete="new-password"
                            />
                            {form.errors.password_confirmation && (
                                <p className="text-xs text-destructive">{form.errors.password_confirmation}</p>
                            )}
                        </div>

                        <Button type="submit" className="w-full" disabled={form.processing}>
                            {t('waiter_accept.submit')}
                        </Button>
                    </form>
                )}
            </div>
        </div>
    );
}
