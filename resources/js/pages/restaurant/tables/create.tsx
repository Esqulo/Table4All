import { Form, Head, Link } from '@inertiajs/react';
import { CheckCircle2, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import TableController from '@/actions/App/Http/Controllers/Restaurant/TableController';
import InputError from '@/components/input-error';
import { QrScanButton } from '@/components/qr-scanner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function CreateTable() {
    const { t } = useTranslation();
    // The customer's one-time QR (optional): the table opens linked to them.
    const [customerToken, setCustomerToken] = useState<string | null>(null);

    return (
        <>
            <Head title={t('tables.breadcrumb_create')} />

            <div className="mx-auto max-w-md space-y-6 p-6">
                <h1 className="font-display text-[2rem] leading-none font-extrabold tracking-[-0.005em] text-balance">
                    {t('tables.breadcrumb_create')}
                </h1>

                <Form {...TableController.store.form()} className="space-y-5">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="title">
                                    {t('tables.title_label')}
                                </Label>
                                <Input
                                    id="title"
                                    name="title"
                                    required
                                    autoFocus
                                    placeholder={t('tables.title_placeholder')}
                                />
                                <InputError message={errors.title} />
                            </div>

                            <div className="grid gap-2">
                                <Label>{t('tables.customer_label')}</Label>
                                {customerToken ? (
                                    <div className="flex items-center gap-2 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
                                        <CheckCircle2 className="h-4 w-4 shrink-0 text-green-600" />
                                        <span className="flex-1">
                                            {t('tables.customer_scanned')}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="h-7 w-7"
                                            aria-label={t(
                                                'tables.customer_remove',
                                            )}
                                            onClick={() =>
                                                setCustomerToken(null)
                                            }
                                        >
                                            <X className="h-4 w-4" />
                                        </Button>
                                        <input
                                            type="hidden"
                                            name="customer_token"
                                            value={customerToken}
                                        />
                                    </div>
                                ) : (
                                    <div>
                                        <QrScanButton
                                            kind="customer"
                                            size="default"
                                            onCode={setCustomerToken}
                                        />
                                    </div>
                                )}
                                <p className="text-xs text-muted-foreground">
                                    {t('tables.customer_hint')}
                                </p>
                                <InputError message={errors.customer_token} />
                            </div>

                            <div className="flex gap-3 pt-2">
                                <Button type="submit" disabled={processing}>
                                    {t('tables.submit_create')}
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={TableController.index.url()}>
                                        {t('tables.cancel')}
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

CreateTable.layout = {
    breadcrumbs: [
        { title: 'tables.breadcrumb_index', href: TableController.index.url() },
        {
            title: 'tables.breadcrumb_create',
            href: TableController.create.url(),
        },
    ],
};
