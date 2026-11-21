import { Head, Link } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowRight,
    BellRing,
    ChartColumn,
    HandCoins,
    Heart,
    Printer,
    ReceiptText,
    Smartphone,
    Users,
    Wifi,
    ListChecks,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { PitchVideo } from '@/components/partner/pitch-video';
import {
    btnPrimary,
    btnSecondary,
    SitePage,
} from '@/components/welcome/site-chrome';
import { contact } from '@/routes';

/**
 * Sales page shown in person to venue owners. Reached only by its direct link
 * (/parceiro): it is not in any navigation and asks search engines not to index it.
 */

const BENEFITS: { key: string; icon: LucideIcon }[] = [
    { key: 'more_rounds', icon: HandCoins },
    { key: 'no_mistakes', icon: ListChecks },
    { key: 'team', icon: BellRing },
    { key: 'bill', icon: ReceiptText },
    { key: 'control', icon: ChartColumn },
    { key: 'return', icon: Heart },
];

const EASY: { key: string; icon: LucideIcon }[] = [
    { key: 'devices', icon: Smartphone },
    { key: 'network', icon: Wifi },
    { key: 'everyone', icon: Users },
    { key: 'print', icon: Printer },
];

const STEPS = ['talk', 'setup', 'tables', 'night'] as const;

const FAQ = [
    'equipment',
    'no_phone',
    'internet',
    'payments',
    'cost',
    'data',
] as const;

export default function Partner() {
    const { t } = useTranslation();

    const cta = (
        <Link href={contact()} className={btnPrimary}>
            {t('partner.cta.primary')}
            <ArrowRight className="size-4" aria-hidden="true" />
        </Link>
    );

    return (
        <>
            <Head title={t('partner.head')}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <SitePage>
                {/* Hero */}
                <section className="mx-auto max-w-[76rem] px-4 pt-10 pb-12 sm:px-6 sm:pt-16 lg:px-8 lg:pt-20">
                    <p className="font-receipt text-[13px] tracking-[0.18em] text-[var(--w-green)] uppercase">
                        {t('partner.hero.eyebrow')}
                    </p>
                    <h1 className="mt-4 max-w-[14ch] font-display text-[clamp(3.1rem,8.5vw,6rem)] leading-[0.9] font-extrabold tracking-[-0.01em] text-balance uppercase">
                        {t('partner.hero.title')}
                    </h1>
                    <p className="mt-7 max-w-[40rem] text-lg leading-relaxed text-[var(--w-ink-soft)] sm:text-xl sm:leading-relaxed">
                        {t('partner.hero.lead')}
                    </p>
                    <div className="mt-9 flex flex-wrap items-center gap-3">
                        {cta}
                        <a href="#video" className={btnSecondary}>
                            {t('partner.cta.watch')}
                            <ArrowDown className="size-4" aria-hidden="true" />
                        </a>
                    </div>
                </section>

                {/* The video */}
                <section
                    id="video"
                    aria-label={t('partner.video.label')}
                    className="mx-auto max-w-[64rem] scroll-mt-6 px-4 pb-20 sm:px-6 lg:px-8"
                >
                    <PitchVideo />
                </section>

                {/* What changes */}
                <section className="mx-auto max-w-[76rem] px-4 pb-20 sm:px-6 sm:pb-28 lg:px-8">
                    <h2 className="max-w-[18ch] font-display text-[clamp(2.4rem,5.5vw,4.25rem)] leading-[0.92] font-extrabold text-balance uppercase">
                        {t('partner.benefits.title')}
                    </h2>
                    <ul className="mt-12 grid gap-x-12 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
                        {BENEFITS.map(({ key, icon: Icon }) => (
                            <li
                                key={key}
                                className="border-t border-[var(--w-rule)] pt-6"
                            >
                                <Icon
                                    className="size-7 text-[var(--w-green)]"
                                    aria-hidden="true"
                                />
                                <h3 className="mt-4 font-display text-[1.75rem] leading-[1.05] font-bold">
                                    {t(`partner.benefits.${key}.title`)}
                                </h3>
                                <p className="mt-3 text-[17px] leading-relaxed text-[var(--w-ink-soft)]">
                                    {t(`partner.benefits.${key}.body`)}
                                </p>
                            </li>
                        ))}
                    </ul>
                </section>

                {/* Easy to adopt: the green tiled wall */}
                <section
                    className="bg-[var(--w-green-ground)] text-[var(--w-on-green)]"
                    style={{
                        backgroundImage:
                            'linear-gradient(color-mix(in oklch, var(--w-on-green) 7%, transparent) 1px, transparent 1px), linear-gradient(90deg, color-mix(in oklch, var(--w-on-green) 7%, transparent) 1px, transparent 1px)',
                        backgroundSize: '56px 56px',
                    }}
                >
                    <div className="mx-auto max-w-[76rem] px-4 py-20 sm:px-6 sm:py-28 lg:px-8">
                        <h2 className="max-w-[16ch] font-display text-[clamp(2.4rem,5.5vw,4.25rem)] leading-[0.92] font-extrabold text-balance uppercase">
                            {t('partner.easy.title')}
                        </h2>
                        <ul className="mt-12 grid gap-x-12 gap-y-10 md:grid-cols-2">
                            {EASY.map(({ key, icon: Icon }) => (
                                <li
                                    key={key}
                                    className="flex gap-5 border-t border-[color-mix(in_oklch,var(--w-on-green)_18%,transparent)] pt-6"
                                >
                                    <Icon
                                        className="mt-1 size-7 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <div>
                                        <h3 className="font-display text-[1.75rem] leading-[1.05] font-bold">
                                            {t(`partner.easy.${key}.title`)}
                                        </h3>
                                        <p className="mt-2 text-[17px] leading-relaxed text-[var(--w-on-green-soft)]">
                                            {t(`partner.easy.${key}.body`)}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>

                {/* How to start */}
                <section className="mx-auto max-w-[76rem] px-4 py-20 sm:px-6 sm:py-28 lg:px-8">
                    <h2 className="max-w-[16ch] font-display text-[clamp(2.4rem,5.5vw,4.25rem)] leading-[0.92] font-extrabold text-balance uppercase">
                        {t('partner.steps.title')}
                    </h2>
                    <ol className="mt-12 grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                        {STEPS.map((step, index) => (
                            <li key={step}>
                                <span className="font-display text-[3.5rem] leading-none font-extrabold text-[var(--w-green)] tabular-nums">
                                    {index + 1}
                                </span>
                                <h3 className="mt-3 font-display text-[1.6rem] leading-[1.05] font-bold">
                                    {t(`partner.steps.${step}.title`)}
                                </h3>
                                <p className="mt-2 text-[17px] leading-relaxed text-[var(--w-ink-soft)]">
                                    {t(`partner.steps.${step}.body`)}
                                </p>
                            </li>
                        ))}
                    </ol>
                </section>

                {/* Questions owners ask */}
                <section className="mx-auto max-w-[76rem] px-4 pb-20 sm:px-6 sm:pb-28 lg:px-8">
                    <h2 className="font-display text-[clamp(2.4rem,5.5vw,4.25rem)] leading-[0.92] font-extrabold text-balance uppercase">
                        {t('partner.faq.title')}
                    </h2>
                    <div className="mt-10 max-w-[48rem] border-b border-[var(--w-rule)]">
                        {FAQ.map((key) => (
                            <details
                                key={key}
                                className="group border-t border-[var(--w-rule)]"
                            >
                                <summary className="flex cursor-pointer list-none items-center justify-between gap-4 py-5 text-lg font-semibold [&::-webkit-details-marker]:hidden">
                                    {t(`partner.faq.${key}.q`)}
                                    <span
                                        aria-hidden="true"
                                        className="font-receipt text-xl text-[var(--w-green)] transition-transform group-open:rotate-45"
                                    >
                                        +
                                    </span>
                                </summary>
                                <p className="pb-6 text-[17px] leading-relaxed text-[var(--w-ink-soft)]">
                                    {t(`partner.faq.${key}.a`)}
                                </p>
                            </details>
                        ))}
                    </div>
                </section>

                {/* Closing */}
                <section className="border-t border-[var(--w-rule)]">
                    <div className="mx-auto max-w-[76rem] px-4 py-24 sm:px-6 sm:py-32 lg:px-8">
                        <h2 className="max-w-[14ch] font-display text-[clamp(2.8rem,7vw,5.5rem)] leading-[0.9] font-extrabold text-balance uppercase">
                            {t('partner.closing.title')}
                        </h2>
                        <p className="mt-6 max-w-[36rem] text-lg leading-relaxed text-[var(--w-ink-soft)]">
                            {t('partner.closing.body')}
                        </p>
                        <div className="mt-9">{cta}</div>
                    </div>
                </section>
            </SitePage>
        </>
    );
}
