import { Form, Head, router, usePage } from '@inertiajs/react';
import { MailPlus, ShieldAlert, ShieldCheck, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import AdminUserController from '@/actions/App/Http/Controllers/Admin/AdminUserController';
import { formatDateTime } from '@/components/dashboard/format';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Auth } from '@/types';

type AdminRow = {
    id: number;
    name: string;
    email: string;
    two_factor: boolean;
    last_login_at: string | null;
    created_at: string | null;
};

type InvitationRow = {
    id: number;
    email: string;
    invited_by: string | null;
    expires_at: string;
};

export default function AdminAdmins({
    admins,
    invitations,
    validHours,
}: {
    admins: AdminRow[];
    invitations: InvitationRow[];
    validHours: number;
}) {
    const { t, i18n } = useTranslation();
    const me = usePage<{ auth: Auth }>().props.auth.user.id;

    function revoke(invitation: InvitationRow) {
        if (
            !window.confirm(
                t('admin_admins.revoke_confirm', { email: invitation.email }),
            )
        ) {
            return;
        }

        router.delete(
            AdminUserController.destroyInvitation.url(invitation.id),
            { preserveScroll: true },
        );
    }

    return (
        <>
            <Head title={t('admin_admins.title')} />

            <div className="space-y-6 p-4 sm:p-6">
                <Heading
                    title={t('admin_admins.title')}
                    description={t('admin_admins.description')}
                />

                <ul className="divide-y divide-border rounded-xl border border-border">
                    {admins.map((admin) => (
                        <li
                            key={admin.id}
                            className="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3"
                        >
                            <div className="min-w-48 flex-1">
                                <p className="font-medium">
                                    {admin.name}
                                    {admin.id === me && (
                                        <span className="ml-2 text-xs text-muted-foreground">
                                            ({t('admin_admins.you')})
                                        </span>
                                    )}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {admin.email}
                                </p>
                            </div>
                            <Badge
                                variant={
                                    admin.two_factor ? 'secondary' : 'outline'
                                }
                            >
                                {admin.two_factor ? (
                                    <ShieldCheck className="size-3" />
                                ) : (
                                    <ShieldAlert className="size-3" />
                                )}
                                {admin.two_factor
                                    ? t('admin_admins.two_factor_on')
                                    : t('admin_admins.two_factor_off')}
                            </Badge>
                            <p className="w-52 text-xs text-muted-foreground">
                                {t('admin_admins.last_login', {
                                    when: admin.last_login_at
                                        ? formatDateTime(
                                              admin.last_login_at,
                                              i18n.language,
                                          )
                                        : t('admin_admins.never'),
                                })}
                            </p>
                        </li>
                    ))}
                </ul>

                <section className="max-w-xl space-y-3 rounded-2xl border bg-card p-4 shadow-xs">
                    <div>
                        <h2 className="font-semibold">
                            {t('admin_admins.invite')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('admin_admins.invite_description', {
                                hours: validHours,
                            })}
                        </p>
                    </div>
                    <Form
                        {...AdminUserController.store.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="flex flex-wrap items-end gap-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid min-w-60 flex-1 gap-2">
                                    <Label htmlFor="invite_email">
                                        {t('admin_admins.email')}
                                    </Label>
                                    <Input
                                        id="invite_email"
                                        name="email"
                                        type="email"
                                        required
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.email} />
                                </div>
                                <Button type="submit" disabled={processing}>
                                    <MailPlus className="size-4" />
                                    {t('admin_admins.send')}
                                </Button>
                            </>
                        )}
                    </Form>
                </section>

                {invitations.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-semibold">
                            {t('admin_admins.pending')}
                        </h2>
                        <ul className="divide-y divide-border rounded-xl border border-border">
                            {invitations.map((invitation) => (
                                <li
                                    key={invitation.id}
                                    className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3"
                                >
                                    <p className="min-w-48 flex-1 text-sm font-medium">
                                        {invitation.email}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {invitation.invited_by &&
                                            t('admin_admins.invited_by', {
                                                name: invitation.invited_by,
                                            }) + ' · '}
                                        {t('admin_admins.expires', {
                                            when: formatDateTime(
                                                invitation.expires_at,
                                                i18n.language,
                                            ),
                                        })}
                                    </p>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t('admin_admins.revoke')}
                                        onClick={() => revoke(invitation)}
                                    >
                                        <X className="size-4" />
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

AdminAdmins.layout = {
    breadcrumbs: [
        { title: 'admin_admins.title', href: AdminUserController.index.url() },
    ],
};
