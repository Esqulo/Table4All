import { Head, Link, router } from '@inertiajs/react';
import { Ban, Search } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import RestaurantController from '@/actions/App/Http/Controllers/Admin/RestaurantController';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import AccountStatusControl from '@/components/admin/account-status-control';
import { formatDateTime } from '@/components/dashboard/format';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

type UserRow = {
    id: number;
    name: string;
    email: string;
    account_type: string;
    staff_role: string | null;
    restaurant: { id: number; name: string; deactivated: boolean } | null;
    email_verified: boolean;
    last_seen_at: string | null;
    created_at: string | null;
    can_deactivate: boolean;
    deactivated_at: string | null;
    deactivated_by: string | null;
    deactivation_reason: string | null;
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    last_page: number;
    total: number;
};

type Props = {
    users: Paginated<UserRow>;
    search: string;
    type: string;
    status: string;
    types: string[];
};

const ALL = '__all';

export default function AdminUsers({
    users,
    search,
    type,
    status,
    types,
}: Props) {
    const { t, i18n } = useTranslation();
    const [query, setQuery] = useState(search);

    function filter(changes: { q?: string; type?: string; status?: string }) {
        const params = { q: query.trim(), type, status, ...changes };
        router.get(
            UserController.index.url(),
            Object.fromEntries(
                Object.entries(params).filter(([, v]) => v !== ''),
            ),
            { preserveState: true },
        );
    }

    const when = (iso: string) => formatDateTime(iso, i18n.language);

    return (
        <>
            <Head title={t('admin_users.title')} />

            <div className="space-y-6 p-4 sm:p-6">
                <Heading
                    title={t('admin_users.title')}
                    description={t('admin_users.description')}
                />

                <div className="flex flex-wrap gap-3">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            filter({ q: query.trim() });
                        }}
                        className="relative w-full max-w-sm"
                    >
                        <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder={t('admin_users.search')}
                            className="pl-9"
                        />
                    </form>
                    <div className="w-48">
                        <Select
                            value={type === '' ? ALL : type}
                            onValueChange={(value) =>
                                filter({ type: value === ALL ? '' : value })
                            }
                        >
                            <SelectTrigger
                                aria-label={t('admin_users.filter_type')}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    {t('admin_users.all_types')}
                                </SelectItem>
                                {types.map((option) => (
                                    <SelectItem key={option} value={option}>
                                        {t(`admin_users.types.${option}`)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="w-48">
                        <Select
                            value={status === '' ? ALL : status}
                            onValueChange={(value) =>
                                filter({ status: value === ALL ? '' : value })
                            }
                        >
                            <SelectTrigger
                                aria-label={t('admin_users.filter_status')}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    {t('admin_users.all_statuses')}
                                </SelectItem>
                                <SelectItem value="active">
                                    {t('admin_users.status_active')}
                                </SelectItem>
                                <SelectItem value="deactivated">
                                    {t('admin_users.status_deactivated')}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <p className="text-sm text-muted-foreground">
                    {t('admin_users.count', { count: users.total })}
                </p>

                {users.data.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-12 text-center text-sm text-muted-foreground">
                        {t('admin_users.empty')}
                    </p>
                ) : (
                    <ul className="divide-y divide-border rounded-xl border border-border">
                        {users.data.map((user) => (
                            <li
                                key={user.id}
                                className={cn(
                                    'flex flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3',
                                    user.deactivated_at && 'bg-muted/40',
                                )}
                            >
                                <div className="min-w-0 flex-1 space-y-1">
                                    <p className="flex flex-wrap items-center gap-2 font-medium">
                                        {user.account_type === 'restaurant' ? (
                                            <Link
                                                href={RestaurantController.show.url(
                                                    user.id,
                                                )}
                                                className="hover:underline"
                                            >
                                                {user.name}
                                            </Link>
                                        ) : (
                                            user.name
                                        )}
                                        <Badge variant="outline">
                                            {user.staff_role
                                                ? t(
                                                      `admin_users.roles.${user.staff_role}`,
                                                  )
                                                : t(
                                                      `admin_users.types.${user.account_type}`,
                                                  )}
                                        </Badge>
                                        {user.deactivated_at && (
                                            <Badge variant="destructive">
                                                <Ban />
                                                {t(
                                                    'admin_users.status_deactivated',
                                                )}
                                            </Badge>
                                        )}
                                        {!user.email_verified && (
                                            <Badge variant="secondary">
                                                {t('admin_users.unverified')}
                                            </Badge>
                                        )}
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {user.email}
                                    </p>
                                    <p className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                        {user.restaurant && (
                                            <span>
                                                {t('admin_users.works_at', {
                                                    name: user.restaurant.name,
                                                })}
                                                {user.restaurant.deactivated &&
                                                    ` · ${t('admin_users.restaurant_deactivated')}`}
                                            </span>
                                        )}
                                        {user.created_at && (
                                            <span>
                                                {t('admin_users.joined', {
                                                    when: when(user.created_at),
                                                })}
                                            </span>
                                        )}
                                        <span>
                                            {user.last_seen_at
                                                ? t('admin_users.last_seen', {
                                                      when: when(
                                                          user.last_seen_at,
                                                      ),
                                                  })
                                                : t('admin_users.never_seen')}
                                        </span>
                                    </p>
                                    {user.deactivated_at && (
                                        <p className="text-xs text-destructive">
                                            {t('admin_users.deactivated_info', {
                                                when: when(user.deactivated_at),
                                                by:
                                                    user.deactivated_by ??
                                                    t(
                                                        'admin_users.unknown_admin',
                                                    ),
                                            })}
                                            {user.deactivation_reason &&
                                                ` — ${user.deactivation_reason}`}
                                        </p>
                                    )}
                                </div>

                                {user.can_deactivate && (
                                    <AccountStatusControl
                                        user={user}
                                        deactivated={
                                            user.deactivated_at !== null
                                        }
                                    />
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {users.last_page > 1 && (
                    <nav className="flex flex-wrap gap-1">
                        {users.links.map((link, i) =>
                            link.url ? (
                                <Link
                                    key={i}
                                    href={link.url}
                                    preserveScroll
                                    className={cn(
                                        'rounded-md border px-3 py-1 text-sm',
                                        link.active &&
                                            'bg-primary text-primary-foreground',
                                    )}
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ) : null,
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}

AdminUsers.layout = {
    breadcrumbs: [
        {
            title: 'admin_users.title',
            href: UserController.index.url(),
        },
    ],
};
