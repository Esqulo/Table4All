import { Head, Link } from '@inertiajs/react';
import { Fragment } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { SitePage } from '@/components/welcome/site-chrome';
import { privacy, terms } from '@/routes';

export type LegalDetails = {
    company_name: string;
    company_document: string | null;
    company_address: string | null;
    dpo_name: string | null;
    dpo_email: string;
    minimum_age: number;
    updated_at: string;
    retention: Record<string, number>;
};

type Section = { title: string; body: string };

/**
 * A section body is plain text from the locale files: paragraphs separated by a blank line,
 * and a paragraph whose lines all start with "- " becomes a bullet list.
 */
function Body({ text }: { text: string }) {
    return text.split(/\n{2,}/).map((block, i) => {
        const lines = block.split('\n');

        if (lines.every((line) => line.startsWith('- '))) {
            return (
                <ul key={i} className="list-disc space-y-1.5 pl-5">
                    {lines.map((line, j) => (
                        <li key={j}>{line.slice(2)}</li>
                    ))}
                </ul>
            );
        }

        return (
            <p key={i}>
                {lines.map((line, j) => (
                    <Fragment key={j}>
                        {j > 0 && <br />}
                        {line}
                    </Fragment>
                ))}
            </p>
        );
    });
}

/** The privacy policy and the terms of use: text from `legal.<doc>.*`, details from the server. */
export function LegalDocument({
    doc,
    legal,
}: {
    doc: 'privacy' | 'terms';
    legal: LegalDetails;
}) {
    const { t, i18n } = useTranslation();

    const controller = [
        legal.company_name,
        legal.company_document &&
            t('legal.common.document', { value: legal.company_document }),
        legal.company_address,
    ]
        .filter(Boolean)
        .join(', ');

    const vars = {
        controller,
        company: legal.company_name,
        dpo: legal.dpo_name ?? t('legal.common.dpo_fallback'),
        dpo_email: legal.dpo_email,
        min_age: legal.minimum_age,
        chat_days: legal.retention.table_chat_days,
        calls_days: legal.retention.table_calls_days,
        admission_days: legal.retention.admission_requests_days,
        failed_jobs_days: legal.retention.failed_jobs_days,
        interpolation: { escapeValue: false },
    };

    const sections = t(`legal.${doc}.sections`, {
        ...vars,
        returnObjects: true,
    }) as Record<string, Section>;

    const updated = new Intl.DateTimeFormat(i18n.language.replace('_', '-'), {
        dateStyle: 'long',
        timeZone: 'UTC',
    }).format(new Date(`${legal.updated_at}T12:00:00Z`));

    const other: { href: string; label: ReactNode } =
        doc === 'privacy'
            ? { href: terms.url(), label: t('legal.terms.head') }
            : { href: privacy.url(), label: t('legal.privacy.head') };

    return (
        <>
            <Head title={t(`legal.${doc}.head`)} />
            <SitePage>
                <article className="mx-auto max-w-[46rem] px-4 pt-10 pb-24 sm:px-6 sm:pt-16">
                    <h1 className="font-display text-[clamp(2.2rem,6vw,3.6rem)] leading-[0.95] font-extrabold text-balance uppercase">
                        {t(`legal.${doc}.title`)}
                    </h1>
                    <p className="mt-4 text-sm text-[var(--w-ink-soft)]">
                        {t('legal.common.updated', { date: updated })}
                    </p>
                    <p className="mt-6 text-lg leading-relaxed text-[var(--w-ink-soft)]">
                        {t(`legal.${doc}.intro`, vars)}
                    </p>

                    <nav
                        aria-label={t('legal.common.contents')}
                        className="mt-10 rounded-2xl border border-[var(--w-rule)] p-5"
                    >
                        <p className="text-sm font-semibold">
                            {t('legal.common.contents')}
                        </p>
                        <ol className="mt-3 list-decimal space-y-1 pl-5 text-[15px] text-[var(--w-ink-soft)]">
                            {Object.entries(sections).map(([id, section]) => (
                                <li key={id}>
                                    <a
                                        href={`#${id}`}
                                        className="underline decoration-[var(--w-rule)] underline-offset-4 hover:text-[var(--w-green)] hover:decoration-[var(--w-green)]"
                                    >
                                        {section.title}
                                    </a>
                                </li>
                            ))}
                        </ol>
                    </nav>

                    {Object.entries(sections).map(([id, section], i) => (
                        <section key={id} id={id} className="mt-12 scroll-mt-8">
                            <h2 className="font-display text-2xl leading-tight font-bold">
                                {i + 1}. {section.title}
                            </h2>
                            <div className="mt-4 space-y-4 text-[16px] leading-relaxed text-[var(--w-ink-soft)]">
                                <Body text={section.body} />
                            </div>
                        </section>
                    ))}

                    <p className="mt-16 border-t border-[var(--w-rule)] pt-6 text-[15px] text-[var(--w-ink-soft)]">
                        {t('legal.common.see_also')}{' '}
                        <Link
                            href={other.href}
                            className="font-medium text-[var(--w-ink)] underline decoration-[var(--w-rule)] underline-offset-4 hover:text-[var(--w-green)] hover:decoration-[var(--w-green)]"
                        >
                            {other.label}
                        </Link>
                    </p>
                </article>
            </SitePage>
        </>
    );
}
