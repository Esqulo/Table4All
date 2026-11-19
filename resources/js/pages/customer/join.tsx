import { Head, Link, router } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { ChevronRight, Utensils } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import JoinController from '@/actions/App/Http/Controllers/Customer/JoinController';
import { QrScanButton } from '@/components/qr-scanner';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';

type JoinedTable = {
    code: string;
    title: string;
    restaurant: string;
    status: 'pending' | 'approved';
};

export default function CustomerJoin({ tables }: { tables: JoinedTable[] }) {
    const { t } = useTranslation();
    const hasTables = tables.length > 0;

    function handleChange(value: string) {
        if (value.length === 6) {
            visitTable(value);
        }
    }

    function visitTable(code: string) {
        router.visit(JoinController.show.url(code));
    }

    return (
        <>
            <Head title={t('join_table.title')} />

            <div className="flex flex-col items-center gap-10 px-4 py-12 sm:py-24">
                {hasTables && (
                    <section className="w-full max-w-sm space-y-3">
                        <div className="space-y-1">
                            <h2 className="text-lg font-bold">
                                {t('join_table.your_tables')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('join_table.your_tables_description')}
                            </p>
                        </div>
                        <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
                            {tables.map((table) => (
                                <li key={table.code}>
                                    <Link
                                        href={JoinController.show.url(
                                            table.code,
                                        )}
                                        className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-muted"
                                    >
                                        <Utensils className="h-5 w-5 shrink-0 text-muted-foreground" />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-medium">
                                                {table.title}
                                            </span>
                                            <span className="block truncate text-sm text-muted-foreground">
                                                {table.restaurant}
                                            </span>
                                            {table.status === 'pending' && (
                                                <span className="mt-0.5 block text-xs font-medium text-amber-600">
                                                    {t(
                                                        'join_table.awaiting_approval',
                                                    )}
                                                </span>
                                            )}
                                        </span>
                                        <span className="font-mono text-sm text-muted-foreground">
                                            {table.code}
                                        </span>
                                        <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section className="flex w-full max-w-sm flex-col items-center gap-8">
                    <div className="space-y-2 text-center">
                        <h1 className="font-display text-[2rem] leading-none font-extrabold tracking-[-0.005em] text-balance">
                            {hasTables
                                ? t('join_table.join_another')
                                : t('join_table.heading')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('join_table.description')}
                        </p>
                    </div>

                    <InputOTP
                        maxLength={6}
                        pattern={REGEXP_ONLY_DIGITS}
                        onChange={handleChange}
                        // With tables listed, don't pop the keyboard over them.
                        autoFocus={!hasTables}
                    >
                        <InputOTPGroup>
                            {Array.from({ length: 6 }, (_, i) => (
                                <InputOTPSlot
                                    key={i}
                                    index={i}
                                    className="h-14 w-12 text-xl"
                                />
                            ))}
                        </InputOTPGroup>
                    </InputOTP>

                    <QrScanButton onCode={visitTable} />
                </section>
            </div>
        </>
    );
}

CustomerJoin.layout = {
    breadcrumbs: [
        { title: 'join_table.title', href: JoinController.index.url() },
    ],
};
