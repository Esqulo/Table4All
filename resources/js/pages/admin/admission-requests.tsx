import { Form, Head, Link } from '@inertiajs/react';
import {
    Archive,
    CheckCheck,
    Mail,
    MessageCircle,
    RotateCcw,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import AdmissionRequestController from '@/actions/App/Http/Controllers/Admin/AdmissionRequestController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { AdmissionRequest, AdmissionRequestStatus } from '@/types';

type Filter = AdmissionRequestStatus | 'all';

type Props = {
    requests: AdmissionRequest[];
    filter: Filter;
    counts: Record<AdmissionRequestStatus, number>;
};

const FILTERS: Filter[] = ['pending', 'contacted', 'archived', 'all'];

const STATUS_STYLES: Record<AdmissionRequestStatus, string> = {
    pending:
        'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300',
    contacted:
        'border-primary/40 bg-primary/10 text-emerald-700 dark:text-emerald-300',
    archived: 'border-border bg-muted text-muted-foreground',
};

function StatusAction({
    request,
    status,
    icon: Icon,
    label,
    variant = 'outline',
}: {
    request: AdmissionRequest;
    status: AdmissionRequestStatus;
    icon: typeof Archive;
    label: string;
    variant?: 'outline' | 'default' | 'ghost';
}) {
    return (
        <Form
            {...AdmissionRequestController.update.form({
                admissionRequest: request.id,
            })}
            options={{ preserveScroll: true }}
        >
            {({ processing }) => (
                <>
                    <input type="hidden" name="status" value={status} />
                    <Button
                        type="submit"
                        size="sm"
                        variant={variant}
                        disabled={processing}
                    >
                        <Icon className="size-4" aria-hidden="true" />
                        {label}
                    </Button>
                </>
            )}
        </Form>
    );
}

export default function AdmissionRequests({ requests, filter, counts }: Props) {
    const { t, i18n } = useTranslation();
    const dateFormat = new Intl.DateTimeFormat(
        i18n.language === 'en' ? 'en-US' : 'pt-BR',
        {
            dateStyle: 'medium',
            timeStyle: 'short',
        },
    );
    const total = counts.pending + counts.contacted + counts.archived;

    return (
        <>
            <Head title={t('admission_requests.title')} />

            <div className="space-y-6 p-6">
                <Heading
                    title={t('admission_requests.title')}
                    description={t('admission_requests.description')}
                />

                <nav
                    aria-label={t('admission_requests.title')}
                    className="flex flex-wrap gap-2"
                >
                    {FILTERS.map((key) => {
                        const count = key === 'all' ? total : counts[key];
                        const active = filter === key;

                        return (
                            <Link
                                key={key}
                                href={AdmissionRequestController.index.url({
                                    query: { status: key },
                                })}
                                preserveScroll
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'inline-flex h-9 items-center gap-2 rounded-full border px-4 text-sm font-medium transition-colors',
                                    active
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-border hover:bg-accent',
                                )}
                            >
                                {t(`admission_requests.filters.${key}`)}
                                <span
                                    className={cn(
                                        'tabular-nums',
                                        active
                                            ? 'opacity-90'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {count}
                                </span>
                            </Link>
                        );
                    })}
                </nav>

                {requests.length === 0 ? (
                    <p className="py-16 text-center text-sm text-muted-foreground">
                        {t(`admission_requests.empty.${filter}`)}
                    </p>
                ) : (
                    <ul className="divide-y divide-border rounded-lg border border-border">
                        {requests.map((request) => {
                            const phoneDigits = request.phone?.replace(
                                /\D/g,
                                '',
                            );
                            const replyHref = `mailto:${request.contact_email}?subject=${encodeURIComponent(t('admission_requests.reply_subject'))}`;

                            return (
                                <li
                                    key={request.id}
                                    className="grid gap-4 p-5 lg:grid-cols-[minmax(0,1fr)_auto]"
                                >
                                    <div className="min-w-0 space-y-3">
                                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                                            <h3 className="text-base font-semibold">
                                                {request.business_name}
                                            </h3>
                                            <Badge
                                                variant="outline"
                                                className={
                                                    STATUS_STYLES[
                                                        request.status
                                                    ]
                                                }
                                            >
                                                {t(
                                                    `admission_requests.status.${request.status}`,
                                                )}
                                            </Badge>
                                            <time
                                                dateTime={request.created_at}
                                                className="text-sm text-muted-foreground tabular-nums"
                                                title={t(
                                                    'admission_requests.fields.received_at',
                                                )}
                                            >
                                                {dateFormat.format(
                                                    new Date(
                                                        request.created_at,
                                                    ),
                                                )}
                                            </time>
                                        </div>

                                        <dl className="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2 xl:grid-cols-4">
                                            <div className="min-w-0">
                                                <dt className="text-muted-foreground">
                                                    {t(
                                                        'admission_requests.fields.contact_email',
                                                    )}
                                                </dt>
                                                <dd className="truncate">
                                                    <a
                                                        href={replyHref}
                                                        className="font-medium underline-offset-4 hover:underline"
                                                    >
                                                        {request.contact_email}
                                                    </a>
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-muted-foreground">
                                                    {t(
                                                        'admission_requests.fields.phone',
                                                    )}
                                                </dt>
                                                <dd className="tabular-nums">
                                                    {request.phone ? (
                                                        <a
                                                            href={`tel:+55${phoneDigits}`}
                                                            className="underline-offset-4 hover:underline"
                                                        >
                                                            {request.phone}
                                                        </a>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-muted-foreground">
                                                    {t(
                                                        'admission_requests.fields.city',
                                                    )}
                                                </dt>
                                                <dd>{request.city || '—'}</dd>
                                            </div>
                                            <div>
                                                <dt className="text-muted-foreground">
                                                    {t(
                                                        'admission_requests.fields.tables',
                                                    )}
                                                </dt>
                                                <dd className="tabular-nums">
                                                    {request.tables
                                                        ? t(
                                                              'admission_requests.tables_count',
                                                              {
                                                                  count: request.tables,
                                                              },
                                                          )
                                                        : '—'}
                                                </dd>
                                            </div>
                                        </dl>

                                        {request.message && (
                                            <div className="text-sm">
                                                <p className="text-muted-foreground">
                                                    {t(
                                                        'admission_requests.fields.message',
                                                    )}
                                                </p>
                                                <p className="mt-0.5 max-w-prose break-words whitespace-pre-wrap">
                                                    {request.message}
                                                </p>
                                            </div>
                                        )}
                                    </div>

                                    <div className="flex flex-wrap content-start items-start gap-2 lg:max-w-[22rem] lg:justify-end">
                                        <Button asChild size="sm">
                                            <a href={replyHref}>
                                                <Mail
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                {t(
                                                    'admission_requests.actions.reply',
                                                )}
                                            </a>
                                        </Button>
                                        {phoneDigits && (
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="outline"
                                            >
                                                <a
                                                    href={`https://wa.me/55${phoneDigits}`}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    <MessageCircle
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    {t(
                                                        'admission_requests.actions.whatsapp',
                                                    )}
                                                </a>
                                            </Button>
                                        )}
                                        {request.status === 'pending' && (
                                            <StatusAction
                                                request={request}
                                                status="contacted"
                                                icon={CheckCheck}
                                                label={t(
                                                    'admission_requests.actions.mark_contacted',
                                                )}
                                            />
                                        )}
                                        {request.status !== 'archived' ? (
                                            <StatusAction
                                                request={request}
                                                status="archived"
                                                icon={Archive}
                                                label={t(
                                                    'admission_requests.actions.archive',
                                                )}
                                                variant="ghost"
                                            />
                                        ) : (
                                            <StatusAction
                                                request={request}
                                                status="pending"
                                                icon={RotateCcw}
                                                label={t(
                                                    'admission_requests.actions.reopen',
                                                )}
                                                variant="ghost"
                                            />
                                        )}
                                        {request.status === 'contacted' && (
                                            <StatusAction
                                                request={request}
                                                status="pending"
                                                icon={RotateCcw}
                                                label={t(
                                                    'admission_requests.actions.reopen',
                                                )}
                                                variant="ghost"
                                            />
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </>
    );
}
