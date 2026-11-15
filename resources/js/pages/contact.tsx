import { Head, useForm } from '@inertiajs/react';
import { Check, Copy, Mail, Send } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import AdmissionRequestController from '@/actions/App/Http/Controllers/AdmissionRequestController';
import {
    btnPrimary,
    btnSecondary,
    SitePage,
} from '@/components/welcome/site-chrome';
import { formatBrPhone } from '@/lib/phone';
import { cn } from '@/lib/utils';

type Props = {
    email: string;
};

type RequestData = {
    business_name: string;
    contact_email: string;
    phone: string;
    city: string;
    tables: string;
    message: string;
    /** Honeypot, must stay empty. */
    website: string;
};

type FieldKey = Exclude<keyof RequestData, 'website' | 'message'>;

const FIELDS: {
    key: FieldKey;
    required?: boolean;
    type?: string;
    inputMode?: 'numeric' | 'tel' | 'email';
    autoComplete: string;
}[] = [
    { key: 'business_name', required: true, autoComplete: 'organization' },
    {
        key: 'contact_email',
        required: true,
        type: 'email',
        inputMode: 'email',
        autoComplete: 'email',
    },
    { key: 'phone', type: 'tel', inputMode: 'tel', autoComplete: 'tel' },
    { key: 'city', autoComplete: 'address-level2' },
    { key: 'tables', inputMode: 'numeric', autoComplete: 'off' },
];

const SUMMARY_KEYS = [
    'business_name',
    'contact_email',
    'phone',
    'city',
    'tables',
] as const;

/** Copy synchronous first (keeps the click's user gesture), then the async Clipboard API with a timeout. */
async function copyText(text: string): Promise<boolean> {
    const previousFocus = document.activeElement as HTMLElement | null;
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    let ok = false;

    try {
        ok = document.execCommand('copy');
    } catch {
        ok = false;
    }

    area.remove();
    previousFocus?.focus();

    if (ok || !navigator.clipboard) {
        return ok;
    }

    try {
        await Promise.race([
            navigator.clipboard.writeText(text),
            new Promise((_, reject) => setTimeout(reject, 1000)),
        ]);

        return true;
    } catch {
        return false;
    }
}

const inputClass =
    'w-full rounded-xl border border-[var(--w-rule)] bg-[var(--w-paper)] px-4 text-[16px] text-[var(--w-paper-ink)] shadow-[inset_0_1px_2px_oklch(0.2_0.02_155/0.06)] transition-colors placeholder:text-[var(--w-paper-soft)] hover:border-[color-mix(in_oklch,var(--w-ink)_35%,transparent)] focus-visible:border-[var(--w-green)] aria-invalid:border-[var(--w-error)]';

export default function Contact({ email }: Props) {
    const { t } = useTranslation();
    const uid = useId();
    const [copy, setCopy] = useState<'idle' | 'copied' | 'failed'>('idle');
    const [sent, setSent] = useState<RequestData | null>(null);
    const formSectionRef = useRef<HTMLElement>(null);

    // Bring the confirmation into view: the form is long and the visitor submits from its bottom.
    useEffect(() => {
        if (!sent || !formSectionRef.current) {
            return;
        }

        const reduce = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;
        formSectionRef.current.scrollIntoView({
            behavior: reduce ? 'auto' : 'smooth',
            block: 'start',
        });
    }, [sent]);
    const form = useForm<RequestData>({
        business_name: '',
        contact_email: '',
        phone: '',
        city: '',
        tables: '',
        message: '',
        website: '',
    });

    useEffect(() => {
        if (copy === 'idle') {
            return;
        }

        const timer = window.setTimeout(() => setCopy('idle'), 2500);

        return () => window.clearTimeout(timer);
    }, [copy]);

    const handleCopy = async () => {
        setCopy((await copyText(email)) ? 'copied' : 'failed');
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        const snapshot = { ...form.data };

        form.post(AdmissionRequestController.store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                setSent(snapshot);
                form.reset();
            },
        });
    };

    const subject = encodeURIComponent(t('contact.email_alt.subject'));
    const to = encodeURIComponent(email);
    const shortcuts = [
        {
            label: t('contact.email_alt.default'),
            href: `mailto:${email}?subject=${subject}`,
            external: false,
        },
        {
            label: t('contact.email_alt.gmail'),
            href: `https://mail.google.com/mail/?view=cm&fs=1&to=${to}&su=${subject}`,
            external: true,
        },
        {
            label: t('contact.email_alt.outlook'),
            href: `https://outlook.live.com/mail/0/deeplink/compose?to=${to}&subject=${subject}`,
            external: true,
        },
    ];

    const summary = sent ?? form.data;
    const summaryFields = SUMMARY_KEYS.map(
        (key) => [key, summary[key].trim()] as const,
    ).filter(([, value]) => value !== '');
    const summaryMessage = summary.message.trim();
    // The slip is a read-only summary: show it only once there is something to summarise.
    const showSummary =
        sent !== null || summaryFields.length > 0 || summaryMessage !== '';
    const errorId = (key: string) => `${uid}-${key}-error`;

    return (
        <>
            <Head title={t('contact.head')} />

            <SitePage>
                <div className="mx-auto grid max-w-[76rem] gap-x-16 gap-y-14 px-4 pt-10 pb-24 sm:px-6 sm:pt-16 lg:grid-cols-[minmax(0,1fr)_25rem] lg:px-8 lg:pt-20 lg:pb-32">
                    <header className="lg:col-span-2">
                        <h1 className="max-w-[20ch] font-display text-[clamp(2.9rem,7.5vw,5.25rem)] leading-[0.9] font-extrabold tracking-[-0.01em] text-balance uppercase">
                            {t('contact.title')}
                        </h1>
                        <p className="mt-7 max-w-[40rem] text-lg leading-relaxed text-[var(--w-ink-soft)] sm:text-xl sm:leading-relaxed">
                            {t('contact.lead')}
                        </p>
                    </header>

                    <div>
                        {/* Admission request: stored for the admin panel and emailed to the team */}
                        <section
                            ref={formSectionRef}
                            aria-labelledby={`${uid}-form-title`}
                            className="scroll-mt-8"
                        >
                            <h2
                                id={`${uid}-form-title`}
                                className="font-display text-[2rem] leading-none font-bold"
                            >
                                {t('contact.form.title')}
                            </h2>

                            {sent ? (
                                <div
                                    role="status"
                                    className="mt-6 max-w-[36rem] border-y border-[var(--w-rule)] py-7"
                                >
                                    <p className="flex items-center gap-2.5 text-xl font-semibold">
                                        <Check
                                            className="size-6 text-[var(--w-green)]"
                                            aria-hidden="true"
                                        />
                                        {t('contact.form.sent_title')}
                                    </p>
                                    <p className="mt-3 text-[17px] leading-relaxed text-[var(--w-ink-soft)]">
                                        {t('contact.form.sent_body', {
                                            name: sent.business_name,
                                            email: sent.contact_email,
                                        })}
                                    </p>
                                    <button
                                        type="button"
                                        onClick={() => setSent(null)}
                                        className="mt-5 text-[15px] font-semibold text-[var(--w-green)] underline decoration-[color-mix(in_oklch,var(--w-green)_45%,transparent)] hover:decoration-[var(--w-green)]"
                                    >
                                        {t('contact.form.send_another')}
                                    </button>
                                </div>
                            ) : (
                                <form
                                    onSubmit={handleSubmit}
                                    noValidate
                                    className="mt-3"
                                >
                                    <p className="max-w-[34rem] text-[16px] leading-relaxed text-[var(--w-ink-soft)]">
                                        {t('contact.form.hint')}
                                    </p>

                                    <div className="mt-7 grid gap-x-5 gap-y-5 sm:grid-cols-2">
                                        {FIELDS.map((field) => {
                                            const error =
                                                form.errors[field.key];

                                            return (
                                                <label
                                                    key={field.key}
                                                    className="grid content-start gap-2"
                                                >
                                                    <span className="text-[15px] font-medium">
                                                        {t(
                                                            `contact.form.fields.${field.key}`,
                                                        )}
                                                        {field.required ? (
                                                            <span className="text-[var(--w-ink-soft)]">
                                                                {' '}
                                                                (
                                                                {t(
                                                                    'contact.form.required',
                                                                )}
                                                                )
                                                            </span>
                                                        ) : null}
                                                    </span>
                                                    <input
                                                        className={cn(
                                                            inputClass,
                                                            'h-12',
                                                        )}
                                                        name={field.key}
                                                        type={
                                                            field.type ?? 'text'
                                                        }
                                                        inputMode={
                                                            field.inputMode
                                                        }
                                                        autoComplete={
                                                            field.autoComplete
                                                        }
                                                        required={
                                                            field.required
                                                        }
                                                        value={
                                                            form.data[field.key]
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                field.key,
                                                                field.key ===
                                                                    'phone'
                                                                    ? formatBrPhone(
                                                                          e
                                                                              .target
                                                                              .value,
                                                                      )
                                                                    : field.key ===
                                                                        'tables'
                                                                      ? e.target.value
                                                                            .replace(
                                                                                /\D/g,
                                                                                '',
                                                                            )
                                                                            .slice(
                                                                                0,
                                                                                4,
                                                                            )
                                                                      : e.target
                                                                            .value,
                                                            )
                                                        }
                                                        placeholder={t(
                                                            `contact.form.placeholders.${field.key}`,
                                                        )}
                                                        aria-invalid={
                                                            error
                                                                ? true
                                                                : undefined
                                                        }
                                                        aria-describedby={
                                                            error
                                                                ? errorId(
                                                                      field.key,
                                                                  )
                                                                : undefined
                                                        }
                                                    />
                                                    {error && (
                                                        <span
                                                            id={errorId(
                                                                field.key,
                                                            )}
                                                            className="text-[14px] text-[var(--w-error)]"
                                                        >
                                                            {error}
                                                        </span>
                                                    )}
                                                </label>
                                            );
                                        })}

                                        <label className="grid gap-2 sm:col-span-2">
                                            <span className="text-[15px] font-medium">
                                                {t(
                                                    'contact.form.fields.message',
                                                )}
                                            </span>
                                            <textarea
                                                className={cn(
                                                    inputClass,
                                                    'min-h-28 resize-y py-3 leading-relaxed',
                                                )}
                                                name="message"
                                                maxLength={2000}
                                                value={form.data.message}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'message',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder={t(
                                                    'contact.form.placeholders.message',
                                                )}
                                                aria-invalid={
                                                    form.errors.message
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    form.errors.message
                                                        ? errorId('message')
                                                        : undefined
                                                }
                                            />
                                            {form.errors.message && (
                                                <span
                                                    id={errorId('message')}
                                                    className="text-[14px] text-[var(--w-error)]"
                                                >
                                                    {form.errors.message}
                                                </span>
                                            )}
                                        </label>

                                        {/* Honeypot: hidden from people and assistive tech, catches naive bots */}
                                        <div
                                            aria-hidden="true"
                                            className="absolute -left-[9999px] size-px overflow-hidden"
                                        >
                                            <label>
                                                Website
                                                <input
                                                    type="text"
                                                    name="website"
                                                    tabIndex={-1}
                                                    autoComplete="off"
                                                    value={form.data.website}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'website',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </label>
                                        </div>
                                    </div>

                                    <div className="mt-8 flex flex-wrap items-center gap-x-5 gap-y-3">
                                        <button
                                            type="submit"
                                            disabled={form.processing}
                                            className={cn(
                                                btnPrimary,
                                                'disabled:cursor-wait disabled:opacity-70',
                                            )}
                                        >
                                            <Send
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            {form.processing
                                                ? t('contact.form.sending')
                                                : t('contact.form.submit')}
                                        </button>
                                        <p className="max-w-[24rem] text-[14px] leading-snug text-[var(--w-ink-soft)]">
                                            {t('contact.form.privacy')}
                                        </p>
                                    </div>
                                    {form.hasErrors && (
                                        <p
                                            role="alert"
                                            className="mt-4 text-[15px] font-medium text-[var(--w-error)]"
                                        >
                                            {t('contact.form.fix_errors')}
                                        </p>
                                    )}
                                </form>
                            )}
                        </section>
                    </div>

                    {/* Right column: the email alternative, plus a live summary slip once the visitor starts typing */}
                    <aside>
                        <div className="mx-auto max-w-[25rem] space-y-10 lg:sticky lg:top-8">
                            {showSummary && (
                                <div>
                                    <figure
                                        aria-label={t('contact.summary.label')}
                                        className="t4a-line-enter relative drop-shadow-[0_18px_28px_oklch(0.25_0.04_155/0.22)]"
                                    >
                                        <div className="t4a-receipt bg-[var(--w-paper)] px-6 pt-6 pb-10 font-receipt text-[13px] leading-relaxed text-[var(--w-paper-ink)] sm:px-7">
                                            <p className="border-b border-dashed border-[var(--w-paper-rule)] pb-4 text-center text-[11px] tracking-[0.18em] text-[var(--w-paper-soft)] uppercase">
                                                {t('contact.summary.label')}
                                            </p>
                                            {summaryFields.length > 0 && (
                                                <dl className="space-y-2.5 py-4">
                                                    {summaryFields.map(
                                                        ([key, value]) => (
                                                            <div
                                                                key={key}
                                                                className="grid gap-0.5"
                                                            >
                                                                <dt className="text-[11.5px] text-[var(--w-paper-soft)]">
                                                                    {t(
                                                                        `contact.form.fields.${key}`,
                                                                    )}
                                                                </dt>
                                                                <dd className="min-w-0 break-words">
                                                                    {value}
                                                                </dd>
                                                            </div>
                                                        ),
                                                    )}
                                                </dl>
                                            )}
                                            {summaryMessage && (
                                                <p className="pt-4 break-words whitespace-pre-wrap [dl+&]:border-t [dl+&]:border-dashed [dl+&]:border-[var(--w-paper-rule)]">
                                                    {summaryMessage}
                                                </p>
                                            )}
                                        </div>

                                        {sent && (
                                            <span
                                                aria-hidden="true"
                                                className="t4a-stamp-enter pointer-events-none absolute right-5 bottom-14 -rotate-[9deg] rounded-md border-[3px] border-[var(--w-stamp)] px-3 pt-1 pb-0.5 font-display text-[2.4rem] leading-none font-extrabold tracking-wide text-[var(--w-stamp)] uppercase opacity-85 mix-blend-multiply"
                                            >
                                                {t('contact.summary.stamp')}
                                            </span>
                                        )}
                                    </figure>
                                    {sent && (
                                        <p className="mt-5 text-center text-[13px] text-[var(--w-ink-soft)]">
                                            {t('contact.summary.note_sent')}
                                        </p>
                                    )}
                                </div>
                            )}

                            {/* Plain email as an alternative, with shortcuts */}
                            <section
                                aria-labelledby={`${uid}-email-title`}
                                className="border-t border-[var(--w-rule)] pt-8"
                            >
                                <h2
                                    id={`${uid}-email-title`}
                                    className="font-display text-[1.6rem] leading-none font-bold"
                                >
                                    {t('contact.email_alt.title')}
                                </h2>
                                <div className="mt-5 flex flex-wrap items-center gap-x-4 gap-y-3">
                                    <a
                                        href={`mailto:${email}`}
                                        className="font-receipt text-[clamp(1.05rem,3vw,1.4rem)] font-medium break-all text-[var(--w-ink)] underline decoration-[var(--w-rule)] hover:text-[var(--w-green)] hover:decoration-[var(--w-green)]"
                                    >
                                        {email}
                                    </a>
                                    <button
                                        type="button"
                                        onClick={handleCopy}
                                        className="inline-flex h-10 items-center gap-2 rounded-full border border-[color-mix(in_oklch,var(--w-ink)_28%,transparent)] px-4 text-[14px] font-semibold transition-colors hover:border-[var(--w-ink)]"
                                    >
                                        {copy === 'copied' ? (
                                            <Check
                                                className="size-4 text-[var(--w-green)]"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <Copy
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        )}
                                        {copy === 'copied'
                                            ? t('contact.email_alt.copied')
                                            : t('contact.email_alt.copy')}
                                    </button>
                                    <span
                                        role="status"
                                        aria-live="polite"
                                        className="w-full text-[14px] text-[var(--w-ink-soft)] empty:hidden"
                                    >
                                        {copy === 'failed'
                                            ? t('contact.email_alt.copy_failed')
                                            : ''}
                                    </span>
                                </div>
                                <div className="mt-5 flex flex-wrap gap-3">
                                    {shortcuts.map((s, i) => (
                                        <a
                                            key={s.href}
                                            href={s.href}
                                            {...(s.external
                                                ? {
                                                      target: '_blank',
                                                      rel: 'noopener noreferrer',
                                                  }
                                                : {})}
                                            className={cn(
                                                btnSecondary,
                                                'h-11 px-5 text-[14px]',
                                            )}
                                        >
                                            {i === 0 && (
                                                <Mail
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {s.label}
                                        </a>
                                    ))}
                                </div>
                            </section>
                        </div>
                    </aside>
                </div>
            </SitePage>
        </>
    );
}
