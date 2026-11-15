import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowRight } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Receipt, RECEIPT_LAST_STAGE } from '@/components/welcome/receipt';
import {
    btnPrimary,
    btnSecondary,
    SitePage,
} from '@/components/welcome/site-chrome';
import { cn } from '@/lib/utils';
import { contact, dashboard, login, register } from '@/routes';

const STEPS = [
    { key: 'order', time: [19, 48] },
    { key: 'queue', time: [20, 2] },
    { key: 'price', time: [20, 15] },
    { key: 'payments', time: [22, 51] },
    { key: 'close', time: [23, 10] },
] as const;

/** Receipt follows the scroll on wide screens; on phones it shows the closed tab. */
function useScrollStory(): boolean {
    const [enabled, setEnabled] = useState(false);

    useEffect(() => {
        const wide = window.matchMedia('(min-width: 1024px)');
        const update = () => setEnabled(wide.matches);

        update();
        wide.addEventListener('change', update);

        return () => wide.removeEventListener('change', update);
    }, []);

    return enabled;
}

export default function Welcome() {
    const { auth } = usePage().props;
    const { t, i18n } = useTranslation();
    const story = useScrollStory();
    const [stage, setStage] = useState(0);
    const [touched, setTouched] = useState(false);
    const storyRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!story || !storyRef.current) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        setStage(
                            Number((entry.target as HTMLElement).dataset.stage),
                        );
                        setTouched(true);
                    }
                }
            },
            { rootMargin: '-50% 0px -50% 0px' },
        );

        storyRef.current
            .querySelectorAll('[data-stage]')
            .forEach((el) => observer.observe(el));

        return () => observer.disconnect();
    }, [story]);

    const receiptStage = story ? stage : RECEIPT_LAST_STAGE;
    const timeFormat = new Intl.DateTimeFormat(
        i18n.language === 'en' ? 'en-US' : 'pt-BR',
        {
            hour: 'numeric',
            minute: '2-digit',
        },
    );
    const formatTime = ([h, m]: readonly [number, number]) =>
        timeFormat.format(new Date(2026, 0, 1, h, m));

    const ownerItems = t('welcome.sides.owners.items', {
        returnObjects: true,
    }) as string[];
    const guestItems = t('welcome.sides.guests.items', {
        returnObjects: true,
    }) as string[];

    const authActions = auth.user ? (
        <Link href={dashboard()} className={btnPrimary}>
            {t('welcome.cta.dashboard')}
            <ArrowRight className="size-4" aria-hidden="true" />
        </Link>
    ) : (
        <>
            <Link href={register()} className={btnPrimary}>
                {t('welcome.cta.register')}
                <ArrowRight className="size-4" aria-hidden="true" />
            </Link>
            <Link href={login()} className={btnSecondary}>
                {t('welcome.cta.login')}
            </Link>
        </>
    );

    return (
        <>
            <Head />

            <SitePage>
                {/* Hero + the night at the table, with the receipt following along */}
                <div
                    ref={storyRef}
                    className="mx-auto grid max-w-[76rem] gap-x-16 px-4 sm:px-6 lg:grid-cols-[minmax(0,1fr)_25rem] lg:px-8"
                >
                    <section
                        data-stage={0}
                        className="pt-10 pb-14 sm:pt-16 lg:col-start-1 lg:min-h-[70vh] lg:pt-20"
                    >
                        <h1 className="max-w-[13ch] font-display text-[clamp(3.1rem,8.5vw,6rem)] leading-[0.9] font-extrabold tracking-[-0.01em] text-balance uppercase">
                            {t('welcome.hero.title')}
                        </h1>
                        <p className="mt-7 max-w-[36rem] text-lg leading-relaxed text-[var(--w-ink-soft)] sm:text-xl sm:leading-relaxed">
                            {t('welcome.hero.lead')}
                        </p>
                        <div className="mt-9 flex flex-wrap items-center gap-3">
                            {authActions}
                        </div>
                        <a
                            href="#como-funciona"
                            className="mt-8 inline-flex items-center gap-2 text-[15px] font-semibold text-[var(--w-green)] underline decoration-[color-mix(in_oklch,var(--w-green)_45%,transparent)] hover:decoration-[var(--w-green)]"
                        >
                            {t('welcome.cta.owners_link')}
                            <ArrowDown className="size-4" aria-hidden="true" />
                        </a>
                    </section>

                    <aside className="pb-16 lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:pt-16 lg:pb-24">
                        <div className="mx-auto max-w-[25rem] lg:sticky lg:top-8">
                            <Receipt
                                stage={receiptStage}
                                animate={story && touched}
                            />
                        </div>
                    </aside>

                    <section
                        id="como-funciona"
                        aria-label={t('welcome.steps.label')}
                        className="scroll-mt-8 pb-20 lg:col-start-1 lg:pb-[20vh]"
                    >
                        <ol>
                            {STEPS.map((step, index) => {
                                const active = story && stage === index + 1;

                                return (
                                    <li
                                        key={step.key}
                                        data-stage={index + 1}
                                        className="grid grid-cols-[3.5rem_minmax(0,1fr)] gap-x-3 sm:grid-cols-[5.5rem_minmax(0,1fr)] sm:gap-x-8 lg:min-h-[58vh]"
                                    >
                                        <time
                                            className={cn(
                                                'pt-1.5 text-right font-receipt text-[13px] tabular-nums transition-colors duration-300',
                                                active
                                                    ? 'text-[var(--w-green)]'
                                                    : 'text-[var(--w-ink-soft)]',
                                            )}
                                        >
                                            {formatTime(step.time)}
                                        </time>
                                        <div className="relative border-l border-[var(--w-rule)] pb-14 pl-5 sm:pl-9">
                                            <span
                                                aria-hidden="true"
                                                className={cn(
                                                    'absolute top-2.5 -left-[5px] size-[9px] rounded-full transition-[background-color,box-shadow] duration-300',
                                                    active
                                                        ? 'bg-[var(--w-green)] shadow-[0_0_0_5px_color-mix(in_oklch,var(--w-green)_18%,transparent)]'
                                                        : 'bg-[var(--w-rule)]',
                                                )}
                                            />
                                            <h2 className="font-display text-[clamp(1.9rem,3.6vw,2.75rem)] leading-[1] font-bold tracking-[-0.005em] text-balance">
                                                {t(
                                                    `welcome.steps.${step.key}.title`,
                                                )}
                                            </h2>
                                            <p className="mt-4 max-w-[34rem] text-[17px] leading-relaxed text-[var(--w-ink-soft)]">
                                                {t(
                                                    `welcome.steps.${step.key}.body`,
                                                )}
                                            </p>
                                        </div>
                                    </li>
                                );
                            })}
                        </ol>
                    </section>
                </div>

                {/* Both sides of the counter: the green tiled wall of a boteco */}
                <section
                    className="bg-[var(--w-green-ground)] text-[var(--w-on-green)]"
                    style={{
                        backgroundImage:
                            'linear-gradient(color-mix(in oklch, var(--w-on-green) 7%, transparent) 1px, transparent 1px), linear-gradient(90deg, color-mix(in oklch, var(--w-on-green) 7%, transparent) 1px, transparent 1px)',
                        backgroundSize: '56px 56px',
                    }}
                >
                    <div className="mx-auto max-w-[76rem] px-4 py-20 sm:px-6 sm:py-28 lg:px-8">
                        <h2 className="max-w-[16ch] font-display text-[clamp(2.6rem,6vw,4.75rem)] leading-[0.92] font-extrabold text-balance uppercase">
                            {t('welcome.sides.title')}
                        </h2>

                        <div className="mt-14 grid gap-14 md:grid-cols-2 md:gap-0">
                            {[
                                {
                                    id: 'bares',
                                    side: 'owners',
                                    items: ownerItems,
                                    cta: login(),
                                    ctaLabel: t('welcome.cta.login'),
                                },
                                {
                                    id: 'clientes',
                                    side: 'guests',
                                    items: guestItems,
                                    cta: register(),
                                    ctaLabel: t('welcome.cta.register'),
                                },
                            ].map((col, i) => (
                                <div
                                    key={col.id}
                                    id={col.id}
                                    className={cn(
                                        'flex scroll-mt-8 flex-col',
                                        i === 0
                                            ? 'md:pr-12'
                                            : 'md:border-l md:border-[color-mix(in_oklch,var(--w-on-green)_22%,transparent)] md:pl-12',
                                    )}
                                >
                                    <h3 className="font-display text-[2rem] leading-none font-bold">
                                        {t(`welcome.sides.${col.side}.title`)}
                                    </h3>
                                    <ul className="mt-6 flex-1">
                                        {col.items.map((item) => (
                                            <li
                                                key={item}
                                                className="border-t border-[color-mix(in_oklch,var(--w-on-green)_16%,transparent)] py-3.5 text-[17px] leading-snug text-[var(--w-on-green-soft)]"
                                            >
                                                {item}
                                            </li>
                                        ))}
                                    </ul>
                                    {col.side === 'owners' && (
                                        <p className="mt-5 text-[15px] text-[var(--w-on-green-soft)]">
                                            {t(
                                                'welcome.sides.owners.contact_prompt',
                                            )}{' '}
                                            <Link
                                                href={contact()}
                                                className="font-medium text-[var(--w-on-green)] underline decoration-[color-mix(in_oklch,var(--w-on-green)_40%,transparent)] underline-offset-4 hover:decoration-[var(--w-on-green)] focus-visible:outline-[var(--w-on-green)]"
                                            >
                                                {t(
                                                    'welcome.sides.owners.contact_link',
                                                )}
                                            </Link>
                                        </p>
                                    )}
                                    {!auth.user && (
                                        <Link
                                            href={col.cta}
                                            className="mt-6 inline-flex items-center gap-2 self-start text-[16px] font-semibold text-[var(--w-on-green)] underline decoration-[color-mix(in_oklch,var(--w-on-green)_40%,transparent)] hover:decoration-[var(--w-on-green)] focus-visible:outline-[var(--w-on-green)]"
                                        >
                                            {col.ctaLabel}
                                            <ArrowRight
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        </Link>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                {/* Closing */}
                <section className="mx-auto max-w-[76rem] px-4 py-24 sm:px-6 sm:py-32 lg:px-8">
                    <h2 className="max-w-[14ch] font-display text-[clamp(2.8rem,7vw,5.5rem)] leading-[0.9] font-extrabold text-balance uppercase">
                        {t('welcome.closing.title')}
                    </h2>
                    <p className="mt-6 max-w-[34rem] text-lg leading-relaxed text-[var(--w-ink-soft)]">
                        {t('welcome.closing.body')}
                    </p>
                    <div className="mt-9 flex flex-wrap items-center gap-3">
                        {authActions}
                    </div>
                </section>
            </SitePage>
        </>
    );
}
