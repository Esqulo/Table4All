import { Form, Head } from '@inertiajs/react';
import { useForm } from '@inertiajs/react';
import { Clock, Mail, Trash2, UserMinus } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import WaiterController from '@/actions/App/Http/Controllers/Restaurant/WaiterController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Waiter, WaiterInvitation } from '@/types';

type Props = {
    waiters: Waiter[];
    invitations: WaiterInvitation[];
};

export default function Waiters({ waiters, invitations }: Props) {
    const { t } = useTranslation();

    const form = useForm({ email: '' });

    function handleInvite(e: React.FormEvent) {
        e.preventDefault();
        form.post(WaiterController.store.url(), {
            onSuccess: () => form.reset(),
        });
    }

    return (
        <>
            <Head title={t('waiters.title')} />

            <div className="space-y-8 p-6">
                <Heading title={t('waiters.title')} />

                {/* invite form */}
                <form
                    onSubmit={handleInvite}
                    className="rounded-lg border border-border bg-card p-5 shadow-xs"
                >
                    <p className="mb-4 text-sm font-medium">{t('waiters.invite_heading')}</p>
                    <div className="flex gap-3">
                        <div className="flex-1 space-y-1">
                            <Label htmlFor="waiter-email">{t('waiters.email_label')}</Label>
                            <Input
                                id="waiter-email"
                                type="email"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                placeholder={t('waiters.email_placeholder')}
                                autoComplete="off"
                            />
                            {form.errors.email && (
                                <p className="text-xs text-destructive">{form.errors.email}</p>
                            )}
                        </div>
                        <div className="flex items-end">
                            <Button type="submit" disabled={form.processing}>
                                <Mail className="mr-2 h-4 w-4" />
                                {t('waiters.invite')}
                            </Button>
                        </div>
                    </div>
                    <p className="mt-2 text-xs text-muted-foreground">{t('waiters.invite_hint')}</p>
                </form>

                {/* pending invitations */}
                {invitations.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide">
                            {t('waiters.pending_heading')}
                        </h2>
                        <ul className="divide-y divide-border rounded-lg border border-border">
                            {invitations.map((inv) => (
                                <li
                                    key={inv.id}
                                    className="flex items-center justify-between gap-3 px-4 py-3"
                                >
                                    <div className="flex items-center gap-3 min-w-0">
                                        <Clock className="h-4 w-4 shrink-0 text-muted-foreground" />
                                        <span className="truncate text-sm text-muted-foreground">
                                            {inv.email}
                                        </span>
                                    </div>
                                    <Form
                                        {...WaiterController.cancelInvitation.form({ invitation: inv.id })}
                                        onBefore={() => confirm(t('waiters.cancel_confirm'))}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="ghost"
                                                size="sm"
                                                disabled={processing}
                                                className="shrink-0 text-muted-foreground hover:text-destructive"
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </Button>
                                        )}
                                    </Form>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {/* active waiters */}
                <section className="space-y-3">
                    <h2 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide">
                        {t('waiters.active_heading')}
                    </h2>
                    {waiters.length === 0 ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">
                            {t('waiters.empty')}
                        </p>
                    ) : (
                        <ul className="divide-y divide-border rounded-lg border border-border">
                            {waiters.map((waiter) => (
                                <li
                                    key={waiter.id}
                                    className="flex items-center justify-between gap-3 px-4 py-3"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">{waiter.name}</p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {waiter.email}
                                        </p>
                                    </div>
                                    <Form
                                        {...WaiterController.destroy.form({ waiter: waiter.id })}
                                        onBefore={() => confirm(t('waiters.delete_confirm'))}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="ghost"
                                                size="sm"
                                                disabled={processing}
                                                className="shrink-0 text-destructive hover:text-destructive"
                                            >
                                                <UserMinus className="h-4 w-4" />
                                            </Button>
                                        )}
                                    </Form>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

Waiters.layout = {
    breadcrumbs: [
        {
            title: 'waiters.breadcrumb_index',
            href: WaiterController.index.url(),
        },
    ],
};
