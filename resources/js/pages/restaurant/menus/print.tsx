import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Loader2, Printer, RotateCcw, Save } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { useTranslation } from 'react-i18next';
import MenuController from '@/actions/App/Http/Controllers/Restaurant/MenuController';
import {
    applyTemplate,
    defaultLayout,
    FONT_CATEGORIES,
    FONTS,
    googleFontsUrl,
    ITEM_STYLES,
    PAPERS,
    TEMPLATE_IDS,
    TEMPLATES,
} from '@/components/menu-print/config';
import type {
    FontId,
    MenuLayout,
    PaperSize,
    TemplateId,
} from '@/components/menu-print/config';
import {
    buildBlocks,
    cardsPerRow,
    geometry,
    paginate,
    reconcile,
    visibleSections,
} from '@/components/menu-print/layout';
import type { Block, PageLayout } from '@/components/menu-print/layout';
import {
    BlockView,
    MenuFooter,
    MenuHeader,
    MenuPage,
    SECTION_GAP,
} from '@/components/menu-print/render';
import type { Theme } from '@/components/menu-print/render';
import { StructureEditor } from '@/components/menu-print/structure-editor';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatBRL } from '@/lib/money';
import type { Category, Menu, Product } from '@/types';

type Props = {
    menu: Menu & { user: { id: number; name: string } };
    layout: Partial<MenuLayout> | null;
    categories: Category[];
    products: Product[];
};

type Tab = 'template' | 'style' | 'content' | 'texts';

const ALL_FONTS_URL = googleFontsUrl(Object.keys(FONTS) as FontId[]);

// ─── small controls ──────────────────────────────────────────────────────────

function SectionLabel({ children }: { children: ReactNode }) {
    return (
        <Label className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
            {children}
        </Label>
    );
}

function Segmented<T extends string | number>({
    value,
    options,
    onChange,
}: {
    value: T;
    options: { value: T; label: string }[];
    onChange: (v: T) => void;
}) {
    return (
        <div
            className="grid gap-1.5"
            style={{
                gridTemplateColumns: `repeat(${options.length > 4 ? 3 : options.length}, minmax(0, 1fr))`,
            }}
        >
            {options.map((o) => (
                <button
                    key={String(o.value)}
                    type="button"
                    onClick={() => onChange(o.value)}
                    className={`rounded-md border px-2 py-1.5 text-xs font-medium transition-colors ${
                        value === o.value
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border hover:border-primary/40'
                    }`}
                >
                    {o.label}
                </button>
            ))}
        </div>
    );
}

function Toggle({
    id,
    checked,
    label,
    onChange,
}: {
    id: string;
    checked: boolean;
    label: string;
    onChange: (v: boolean) => void;
}) {
    return (
        <div className="flex items-center gap-2">
            <Checkbox
                id={id}
                checked={checked}
                onCheckedChange={(v) => onChange(v === true)}
            />
            <Label htmlFor={id} className="text-sm font-normal">
                {label}
            </Label>
        </div>
    );
}

function FontSelect({
    id,
    value,
    onChange,
}: {
    id: string;
    value: FontId;
    onChange: (f: FontId) => void;
}) {
    const { t } = useTranslation();

    return (
        <select
            id={id}
            value={value}
            onChange={(e) => onChange(e.target.value as FontId)}
            className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
            style={{ fontFamily: FONTS[value].stack }}
        >
            {FONT_CATEGORIES.map((cat) => (
                <optgroup key={cat} label={t(`menus.print_font_cat_${cat}`)}>
                    {(Object.keys(FONTS) as FontId[])
                        .filter((f) => FONTS[f].category === cat)
                        .map((f) => (
                            <option
                                key={f}
                                value={f}
                                style={{ fontFamily: FONTS[f].stack }}
                            >
                                {FONTS[f].label}
                            </option>
                        ))}
                </optgroup>
            ))}
        </select>
    );
}

function TemplateThumbnail({
    id,
    active,
    onClick,
}: {
    id: TemplateId;
    active: boolean;
    onClick: () => void;
}) {
    const { t } = useTranslation();
    const tpl = TEMPLATES[id];
    const c = tpl.palette;
    const bar = (w: string, color = c.muted, h = 3) => (
        <div
            style={{
                height: h,
                width: w,
                background: color,
                borderRadius: 1,
                opacity: 0.55,
            }}
        />
    );

    const heading = {
        underline: (
            <div
                style={{
                    borderBottom: `2px solid ${tpl.accent}`,
                    paddingBottom: 2,
                }}
            >
                {bar('55%', c.fg, 4)}
            </div>
        ),
        centered: (
            <div style={{ display: 'flex', alignItems: 'center', gap: 3 }}>
                <span style={{ flex: 1, height: 1, background: c.line }} />
                {bar('40%', tpl.accent, 4)}
                <span style={{ flex: 1, height: 1, background: c.line }} />
            </div>
        ),
        band: (
            <div
                style={{ background: tpl.accent, borderRadius: 2, padding: 2 }}
            >
                {bar('45%', '#fff', 3)}
            </div>
        ),
        bar: (
            <div
                style={{
                    borderLeft: `3px solid ${tpl.accent}`,
                    paddingLeft: 3,
                }}
            >
                {bar('60%', c.fg, 5)}
            </div>
        ),
        minimal: (
            <div
                style={{
                    borderBottom: `1px solid ${c.line}`,
                    paddingBottom: 2,
                }}
            >
                {bar('35%', tpl.accent, 2)}
            </div>
        ),
        chalk: (
            <div style={{ display: 'flex', justifyContent: 'center' }}>
                {bar('50%', tpl.accent, 5)}
            </div>
        ),
    }[tpl.heading];

    const item = (k: number) =>
        tpl.item === 'grid' ? (
            <div
                key={k}
                style={{
                    border: `1px solid ${c.line}`,
                    borderRadius: 2,
                    overflow: 'hidden',
                }}
            >
                <div
                    style={{
                        height: 10,
                        background: c.card === '#f5f5f5' ? '#ddd' : c.card,
                    }}
                />
                <div style={{ padding: 2 }}>{bar('70%', c.fg, 2)}</div>
            </div>
        ) : (
            <div
                key={k}
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 3,
                    padding: '2px 0',
                    borderBottom:
                        tpl.item === 'leaders' ? 'none' : `1px solid ${c.line}`,
                }}
            >
                {tpl.item === 'photo' && (
                    <div
                        style={{
                            width: 8,
                            height: 8,
                            borderRadius: 2,
                            background: '#ddd',
                        }}
                    />
                )}
                {bar('45%', c.fg, 2)}
                <span
                    style={{
                        flex: 1,
                        borderBottom:
                            tpl.item === 'leaders'
                                ? `1px dotted ${c.muted}`
                                : 'none',
                    }}
                />
                {bar('15%', tpl.accent === '#151515' ? c.fg : tpl.accent, 2)}
            </div>
        );

    const column = (n: number) => (
        <div
            style={{
                flex: 1,
                display: 'flex',
                flexDirection: 'column',
                gap: 3,
            }}
        >
            {heading}
            <div
                style={
                    tpl.item === 'grid'
                        ? {
                              display: 'grid',
                              gridTemplateColumns: 'repeat(3, 1fr)',
                              gap: 2,
                          }
                        : { display: 'flex', flexDirection: 'column' }
                }
            >
                {Array.from({ length: n }, (_, k) => item(k))}
            </div>
        </div>
    );

    return (
        <button
            type="button"
            onClick={onClick}
            className={`flex flex-col items-center gap-1.5 rounded-lg border-2 p-1.5 transition-colors ${active ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40'}`}
        >
            <div
                className="aspect-[3/4] w-full overflow-hidden rounded shadow-sm"
                style={{
                    background: c.bg,
                    padding: 6,
                    display: 'flex',
                    gap: 5,
                }}
            >
                {column(tpl.item === 'grid' ? 6 : 5)}
                {tpl.columns > 1 && column(5)}
            </div>
            <span className="text-xs font-medium">
                {t(`menus.print_tpl_${id}`)}
            </span>
        </button>
    );
}

// ─── printing ────────────────────────────────────────────────────────────────

/** Copies the rendered pages into a hidden frame, waits for fonts and pictures, prints. */
async function printPages(
    pages: HTMLElement[],
    layout: MenuLayout,
    title: string,
) {
    const paper = PAPERS[layout.paper];
    const [w, h] = layout.landscape ? [paper.h, paper.w] : [paper.w, paper.h];
    const fonts = googleFontsUrl([layout.heading_font, layout.body_font]);

    const frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    Object.assign(frame.style, {
        position: 'fixed',
        right: '0',
        bottom: '0',
        width: '0',
        height: '0',
        border: '0',
        visibility: 'hidden',
    });
    document.body.appendChild(frame);

    const doc = frame.contentDocument!;
    doc.open();
    doc.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title></title>
${fonts ? `<link rel="stylesheet" href="${fonts}">` : ''}
<style>
@page { size: ${w}mm ${h}mm; margin: 0; }
html, body { margin: 0; padding: 0; }
* { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.mp-page { break-after: page; page-break-after: always; height: ${h - 0.4}mm !important; width: ${w}mm !important; }
.mp-page:last-child { break-after: auto; page-break-after: auto; }
</style></head><body>${pages.map((p) => p.outerHTML).join('')}</body></html>`);
    doc.close();
    doc.title = title;

    const timeout = (ms: number) => new Promise((r) => setTimeout(r, ms));
    const images = Array.from(doc.images).map((img) =>
        img.complete
            ? Promise.resolve()
            : new Promise((r) => {
                  img.addEventListener('load', r, { once: true });
                  img.addEventListener('error', r, { once: true });
              }),
    );
    await Promise.race([
        Promise.all([doc.fonts.ready, ...images]),
        timeout(8000),
    ]);
    await timeout(100);

    const win = frame.contentWindow!;
    const cleanup = () => setTimeout(() => frame.remove(), 500);
    win.addEventListener('afterprint', cleanup, { once: true });
    win.focus();
    win.print();
    setTimeout(() => frame.isConnected && frame.remove(), 60_000);
}

// ─── page ────────────────────────────────────────────────────────────────────

export default function PrintMenu({
    menu,
    layout: savedLayout,
    categories,
    products,
}: Props) {
    const { t } = useTranslation();

    const [layout, setLayout] = useState<MenuLayout>(() =>
        reconcile(savedLayout, categories, products, menu.name),
    );
    const [savedJson, setSavedJson] = useState(() =>
        JSON.stringify(reconcile(savedLayout, categories, products, menu.name)),
    );
    const [tab, setTab] = useState<Tab>('template');
    const [saving, setSaving] = useState(false);
    const [printing, setPrinting] = useState(false);
    const [fontsVersion, setFontsVersion] = useState(0);

    const dirty = JSON.stringify(layout) !== savedJson;
    const patch = useCallback(
        (p: Partial<MenuLayout>) => setLayout((l) => ({ ...l, ...p })),
        [],
    );

    // Fonts: one stylesheet for the whole editor (picker previews included);
    // re-measure whenever a font finishes loading.
    useEffect(() => {
        if (ALL_FONTS_URL && !document.getElementById('menu-print-fonts')) {
            document.head.appendChild(
                Object.assign(document.createElement('link'), {
                    id: 'menu-print-fonts',
                    rel: 'stylesheet',
                    href: ALL_FONTS_URL,
                }),
            );
        }

        const bump = () => setFontsVersion((v) => v + 1);
        document.fonts.addEventListener('loadingdone', bump);

        return () => document.fonts.removeEventListener('loadingdone', bump);
    }, []);

    useEffect(() => {
        let alive = true;
        const fams = [
            FONTS[layout.heading_font].stack,
            FONTS[layout.body_font].stack,
        ];
        Promise.all(
            fams.flatMap((f) => [
                document.fonts.load(`400 16px ${f}`),
                document.fonts.load(`700 16px ${f}`),
            ]),
        )
            .catch(() => undefined)
            .then(() => alive && setFontsVersion((v) => v + 1));

        return () => {
            alive = false;
        };
    }, [layout.heading_font, layout.body_font]);

    useEffect(() => {
        if (!dirty) {
            return;
        }

        const warn = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    // ── derived content ──
    const tpl = TEMPLATES[layout.template];
    const geo = geometry(layout);
    const perRow = cardsPerRow(geo.columnW, layout.scale);

    const theme: Theme = useMemo(
        () => ({
            palette: tpl.palette,
            heading: tpl.heading,
            accent: layout.accent,
            headingFont: FONTS[layout.heading_font].stack,
            bodyFont: FONTS[layout.body_font].stack,
            scale: layout.scale,
            showDescriptions: layout.show_descriptions,
            showImages: layout.show_images,
            perRow,
            price: (p: Product) =>
                formatBRL(p.price) +
                (p.price_type === 'unit'
                    ? ''
                    : ` / ${t(`products.price_suffix.${p.price_type}`)}`),
        }),
        [
            tpl,
            layout.accent,
            layout.heading_font,
            layout.body_font,
            layout.scale,
            layout.show_descriptions,
            layout.show_images,
            perRow,
            t,
        ],
    );

    const sections = useMemo(
        () =>
            visibleSections(
                layout,
                categories,
                products,
                t('menus.print_uncategorised'),
            ),
        [layout, categories, products, t],
    );
    const blocks = useMemo(
        () => buildBlocks(sections, perRow),
        [sections, perRow],
    );

    const headerTitle = layout.title?.trim() || menu.name;
    const headerSubtitle = layout.subtitle?.trim() ?? '';
    const footerNote = layout.footer?.trim() ?? '';
    const header = layout.show_header ? (
        <MenuHeader title={headerTitle} subtitle={headerSubtitle} t={theme} />
    ) : null;
    const footer =
        footerNote || layout.show_page_numbers
            ? { note: footerNote, showNumbers: layout.show_page_numbers }
            : null;

    // ── measurement → pagination ──
    const measureRef = useRef<HTMLDivElement>(null);
    // Pages keep the blocks they were paginated from: when the content changes,
    // the next render still uses the old pair until it's re-measured.
    const [paged, setPaged] = useState<{
        blocks: Block[];
        pages: PageLayout[];
    }>({ blocks: [], pages: [] });
    const pages = paged.pages;

    useLayoutEffect(() => {
        const root = measureRef.current;

        if (!root) {
            return;
        }

        const h = (sel: string) =>
            (
                root.querySelector(sel) as HTMLElement | null
            )?.getBoundingClientRect().height ?? 0;
        const heights = Array.from(
            root.querySelectorAll<HTMLElement>('[data-block]'),
        ).map((el) => el.getBoundingClientRect().height);
        const footerH = Math.ceil(h('[data-footer]'));
        const capacity = geo.contentH - footerH;

        setPaged({
            blocks,
            pages: paginate(blocks, {
                heights,
                sectionGap: Math.round(SECTION_GAP * layout.scale * 10) / 10,
                columns: geo.columns,
                capacity,
                firstPageCapacity: capacity - Math.ceil(h('[data-header]')),
                sectionPageBreak: layout.section_page_break,
            }),
        });
        // fontsVersion forces a re-measure once web fonts arrive.
    }, [
        blocks,
        theme,
        geo.contentH,
        geo.columnW,
        geo.columns,
        layout.section_page_break,
        layout.show_header,
        headerTitle,
        headerSubtitle,
        footerNote,
        layout.show_page_numbers,
        layout.scale,
        fontsVersion,
    ]);

    // ── preview scaling ──
    const previewRef = useRef<HTMLDivElement>(null);
    const [previewW, setPreviewW] = useState(600);
    useEffect(() => {
        const el = previewRef.current;

        if (!el) {
            return;
        }

        const ro = new ResizeObserver(([entry]) =>
            setPreviewW(entry.contentRect.width),
        );
        ro.observe(el);

        return () => ro.disconnect();
    }, []);
    const scale = Math.min(1, (previewW - 32) / geo.pageW, 700 / geo.pageW);

    // ── actions ──
    const pagesRef = useRef<HTMLDivElement>(null);

    async function handlePrint() {
        const nodes = Array.from(
            pagesRef.current?.querySelectorAll<HTMLElement>('.mp-page') ?? [],
        );

        if (!nodes.length) {
            return;
        }

        setPrinting(true);

        try {
            await printPages(nodes, layout, `${menu.user.name} — ${menu.name}`);
        } finally {
            setPrinting(false);
        }
    }

    function handleSave() {
        const snapshot = JSON.stringify(layout);
        router.put(
            MenuController.updateLayout.url({ menu: menu.id }),
            { layout: JSON.parse(snapshot) },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => setSavedJson(snapshot),
            },
        );
    }

    function handleReset() {
        setLayout(
            reconcile(
                {
                    ...defaultLayout(menu.name),
                    sections: [],
                    flat_order: [],
                    hidden_products: [],
                },
                categories,
                products,
                menu.name,
            ),
        );
    }

    const tabs: { id: Tab; label: string }[] = [
        { id: 'template', label: t('menus.print_tab_template') },
        { id: 'style', label: t('menus.print_tab_style') },
        { id: 'content', label: t('menus.print_tab_content') },
        { id: 'texts', label: t('menus.print_tab_texts') },
    ];

    return (
        <>
            <Head title={`${t('menus.print_heading')} — ${menu.name}`} />

            <div className="flex h-dvh flex-col bg-background">
                <header className="flex shrink-0 flex-wrap items-center gap-2 border-b border-border px-4 py-3">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={MenuController.index.url()}>
                            <ArrowLeft className="mr-1.5 h-4 w-4" />
                            {t('menus.breadcrumb_index')}
                        </Link>
                    </Button>
                    <span className="text-muted-foreground">/</span>
                    <h1 className="min-w-0 truncate text-sm font-semibold">
                        {menu.name} — {t('menus.print_heading')}
                    </h1>
                    <div className="ml-auto flex items-center gap-2">
                        <span className="hidden text-xs text-muted-foreground sm:inline">
                            {dirty
                                ? t('menus.print_unsaved')
                                : t('menus.print_saved')}
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={handleReset}
                            title={t('menus.print_reset')}
                        >
                            <RotateCcw className="h-4 w-4" />
                            <span className="sr-only sm:not-sr-only sm:ml-1.5">
                                {t('menus.print_reset')}
                            </span>
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={handleSave}
                            disabled={!dirty || saving}
                        >
                            {saving ? (
                                <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                            ) : (
                                <Save className="mr-1.5 h-4 w-4" />
                            )}
                            {t('menus.print_save')}
                        </Button>
                        <Button
                            size="sm"
                            onClick={handlePrint}
                            disabled={printing || blocks.length === 0}
                        >
                            {printing ? (
                                <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                            ) : (
                                <Printer className="mr-1.5 h-4 w-4" />
                            )}
                            {t('menus.print_button')}
                        </Button>
                    </div>
                </header>

                <div className="flex flex-1 flex-col overflow-hidden lg:flex-row">
                    {/* ── editor ── */}
                    <aside className="flex max-h-[45dvh] shrink-0 flex-col border-b border-border lg:max-h-none lg:w-80 lg:border-r lg:border-b-0 xl:w-96">
                        <nav className="grid shrink-0 grid-cols-4 border-b border-border">
                            {tabs.map((tb) => (
                                <button
                                    key={tb.id}
                                    type="button"
                                    onClick={() => setTab(tb.id)}
                                    className={`border-b-2 px-1 py-2.5 text-xs font-medium transition-colors ${
                                        tab === tb.id
                                            ? 'border-primary text-foreground'
                                            : 'border-transparent text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    {tb.label}
                                </button>
                            ))}
                        </nav>

                        <div className="flex-1 space-y-6 overflow-y-auto p-4">
                            {tab === 'template' && (
                                <>
                                    <section className="space-y-2">
                                        <SectionLabel>
                                            {t('menus.print_template')}
                                        </SectionLabel>
                                        <p className="text-xs text-muted-foreground">
                                            {t('menus.print_template_hint')}
                                        </p>
                                        <div className="grid grid-cols-3 gap-1.5 xl:grid-cols-4">
                                            {TEMPLATE_IDS.map((id) => (
                                                <TemplateThumbnail
                                                    key={id}
                                                    id={id}
                                                    active={
                                                        layout.template === id
                                                    }
                                                    onClick={() =>
                                                        setLayout((l) =>
                                                            applyTemplate(
                                                                l,
                                                                id,
                                                            ),
                                                        )
                                                    }
                                                />
                                            ))}
                                        </div>
                                    </section>

                                    <section className="space-y-2">
                                        <SectionLabel>
                                            {t('menus.print_item_style')}
                                        </SectionLabel>
                                        <Segmented
                                            value={layout.item_style}
                                            options={ITEM_STYLES.map((s) => ({
                                                value: s,
                                                label: t(
                                                    `menus.print_style_${s}`,
                                                ),
                                            }))}
                                            onChange={(item_style) =>
                                                patch({ item_style })
                                            }
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            {t('menus.print_mix_hint')}
                                        </p>
                                    </section>

                                    <section className="space-y-2">
                                        <SectionLabel>
                                            {t('menus.print_paper')}
                                        </SectionLabel>
                                        <Segmented
                                            value={layout.paper}
                                            options={(
                                                Object.keys(
                                                    PAPERS,
                                                ) as PaperSize[]
                                            ).map((p) => ({
                                                value: p,
                                                label: p,
                                            }))}
                                            onChange={(paper) =>
                                                patch({ paper })
                                            }
                                        />
                                        <Segmented
                                            value={layout.landscape ? 'l' : 'p'}
                                            options={[
                                                {
                                                    value: 'p',
                                                    label: t(
                                                        'menus.print_portrait',
                                                    ),
                                                },
                                                {
                                                    value: 'l',
                                                    label: t(
                                                        'menus.print_landscape',
                                                    ),
                                                },
                                            ]}
                                            onChange={(v) =>
                                                patch({ landscape: v === 'l' })
                                            }
                                        />
                                    </section>

                                    <section className="space-y-2">
                                        <SectionLabel>
                                            {t('menus.print_columns')}
                                        </SectionLabel>
                                        <Segmented
                                            value={layout.columns}
                                            options={[1, 2, 3].map((n) => ({
                                                value: n as 1 | 2 | 3,
                                                label: String(n),
                                            }))}
                                            onChange={(columns) =>
                                                patch({ columns })
                                            }
                                        />
                                    </section>
                                </>
                            )}

                            {tab === 'style' && (
                                <>
                                    <section className="space-y-2">
                                        <SectionLabel>
                                            {t('menus.print_font')}
                                        </SectionLabel>
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor="heading-font"
                                                className="text-xs font-normal text-muted-foreground"
                                            >
                                                {t('menus.print_heading_font')}
                                            </Label>
                                            <FontSelect
                                                id="heading-font"
                                                value={layout.heading_font}
                                                onChange={(heading_font) =>
                                                    patch({ heading_font })
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor="body-font"
                                                className="text-xs font-normal text-muted-foreground"
                                            >
                                                {t('menus.print_body_font')}
                                            </Label>
                                            <FontSelect
                                                id="body-font"
                                                value={layout.body_font}
                                                onChange={(body_font) =>
                                                    patch({ body_font })
                                                }
                                            />
                                        </div>
                                    </section>

                                    <section className="space-y-2">
                                        <SectionLabel>
                                            {t('menus.print_text_size')}
                                        </SectionLabel>
                                        <div className="flex items-center gap-3">
                                            <input
                                                type="range"
                                                min={0.7}
                                                max={1.4}
                                                step={0.05}
                                                value={layout.scale}
                                                onChange={(e) =>
                                                    patch({
                                                        scale: Number(
                                                            e.target.value,
                                                        ),
                                                    })
                                                }
                                                className="flex-1 accent-primary"
                                                aria-label={t(
                                                    'menus.print_text_size',
                                                )}
                                            />
                                            <span className="w-10 text-right text-xs text-muted-foreground tabular-nums">
                                                {Math.round(layout.scale * 100)}
                                                %
                                            </span>
                                        </div>
                                    </section>

                                    <section className="space-y-2">
                                        <SectionLabel>
                                            {t('menus.print_accent')}
                                        </SectionLabel>
                                        <div className="flex items-center gap-2">
                                            <input
                                                type="color"
                                                value={layout.accent}
                                                onChange={(e) =>
                                                    patch({
                                                        accent: e.target.value,
                                                    })
                                                }
                                                className="h-9 w-12 cursor-pointer rounded border border-input bg-background p-0.5"
                                                aria-label={t(
                                                    'menus.print_accent',
                                                )}
                                            />
                                            <span className="font-mono text-xs text-muted-foreground uppercase">
                                                {layout.accent}
                                            </span>
                                            {layout.accent !== tpl.accent && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        patch({
                                                            accent: tpl.accent,
                                                        })
                                                    }
                                                >
                                                    {t(
                                                        'menus.print_accent_reset',
                                                    )}
                                                </Button>
                                            )}
                                        </div>
                                    </section>

                                    <section className="space-y-3">
                                        <SectionLabel>
                                            {t('menus.print_display')}
                                        </SectionLabel>
                                        <Toggle
                                            id="show-desc"
                                            checked={layout.show_descriptions}
                                            label={t(
                                                'menus.print_show_descriptions',
                                            )}
                                            onChange={(show_descriptions) =>
                                                patch({ show_descriptions })
                                            }
                                        />
                                        <Toggle
                                            id="show-img"
                                            checked={layout.show_images}
                                            label={t('menus.print_show_images')}
                                            onChange={(show_images) =>
                                                patch({ show_images })
                                            }
                                        />
                                    </section>
                                </>
                            )}

                            {tab === 'content' && (
                                <>
                                    <section className="space-y-3">
                                        <Toggle
                                            id="grouped"
                                            checked={layout.grouped}
                                            label={t('menus.print_grouped')}
                                            onChange={(grouped) =>
                                                patch({ grouped })
                                            }
                                        />
                                        {layout.grouped && (
                                            <Toggle
                                                id="page-break"
                                                checked={
                                                    layout.section_page_break
                                                }
                                                label={t(
                                                    'menus.print_section_page_break',
                                                )}
                                                onChange={(
                                                    section_page_break,
                                                ) =>
                                                    patch({
                                                        section_page_break,
                                                    })
                                                }
                                            />
                                        )}
                                    </section>
                                    <section className="space-y-2">
                                        <SectionLabel>
                                            {t('menus.print_order')}
                                        </SectionLabel>
                                        <StructureEditor
                                            layout={layout}
                                            categories={categories}
                                            products={products}
                                            onChange={patch}
                                        />
                                    </section>
                                </>
                            )}

                            {tab === 'texts' && (
                                <>
                                    <section className="space-y-3">
                                        <Toggle
                                            id="show-header"
                                            checked={layout.show_header}
                                            label={t('menus.print_show_header')}
                                            onChange={(show_header) =>
                                                patch({ show_header })
                                            }
                                        />
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="mp-title">
                                                {t('menus.print_title')}
                                            </Label>
                                            <Input
                                                id="mp-title"
                                                value={layout.title ?? ''}
                                                maxLength={120}
                                                placeholder={menu.name}
                                                onChange={(e) =>
                                                    patch({
                                                        title: e.target.value,
                                                    })
                                                }
                                                disabled={!layout.show_header}
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="mp-subtitle">
                                                {t('menus.print_subtitle')}
                                            </Label>
                                            <Input
                                                id="mp-subtitle"
                                                value={layout.subtitle ?? ''}
                                                maxLength={160}
                                                placeholder={menu.user.name}
                                                onChange={(e) =>
                                                    patch({
                                                        subtitle:
                                                            e.target.value ||
                                                            null,
                                                    })
                                                }
                                                disabled={!layout.show_header}
                                            />
                                        </div>
                                    </section>
                                    <section className="space-y-3">
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="mp-footer">
                                                {t('menus.print_footer')}
                                            </Label>
                                            <Input
                                                id="mp-footer"
                                                value={layout.footer ?? ''}
                                                maxLength={240}
                                                placeholder={t(
                                                    'menus.print_footer_placeholder',
                                                )}
                                                onChange={(e) =>
                                                    patch({
                                                        footer:
                                                            e.target.value ||
                                                            null,
                                                    })
                                                }
                                            />
                                        </div>
                                        <Toggle
                                            id="page-numbers"
                                            checked={layout.show_page_numbers}
                                            label={t(
                                                'menus.print_show_page_numbers',
                                            )}
                                            onChange={(show_page_numbers) =>
                                                patch({ show_page_numbers })
                                            }
                                        />
                                    </section>
                                </>
                            )}
                        </div>
                    </aside>

                    {/* ── preview ── */}
                    <main
                        ref={previewRef}
                        className="flex flex-1 flex-col items-center gap-6 overflow-y-auto bg-muted/30 px-4 py-6"
                    >
                        {blocks.length === 0 ? (
                            <p className="mt-16 text-sm text-muted-foreground">
                                {products.length === 0
                                    ? t('menus.print_no_products')
                                    : t('menus.print_all_hidden')}
                            </p>
                        ) : (
                            <>
                                <p className="text-xs text-muted-foreground">
                                    {t('menus.print_pages_count', {
                                        count: pages.length,
                                    })}
                                </p>
                                <div
                                    ref={pagesRef}
                                    className="flex flex-col items-center gap-6"
                                >
                                    {pages.map((page, i) => (
                                        <div
                                            key={i}
                                            className="flex flex-col items-center gap-1.5"
                                        >
                                            <div
                                                className="shadow-lg"
                                                style={{
                                                    width: geo.pageW * scale,
                                                    height: geo.pageH * scale,
                                                    overflow: 'hidden',
                                                    position: 'relative',
                                                }}
                                            >
                                                <div
                                                    style={{
                                                        position: 'absolute',
                                                        top: 0,
                                                        left: 0,
                                                        transform: `scale(${scale})`,
                                                        transformOrigin:
                                                            'top left',
                                                    }}
                                                >
                                                    <MenuPage
                                                        page={page}
                                                        index={i}
                                                        total={pages.length}
                                                        blocks={paged.blocks}
                                                        geo={geo}
                                                        t={theme}
                                                        header={header}
                                                        footer={footer}
                                                    />
                                                </div>
                                            </div>
                                            <span className="text-xs text-muted-foreground">
                                                {t('menus.print_page')} {i + 1}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </>
                        )}
                    </main>
                </div>
            </div>

            {/* Off-screen measuring area: same components, real size. */}
            <div
                ref={measureRef}
                aria-hidden="true"
                style={{
                    position: 'fixed',
                    left: -100000,
                    top: 0,
                    visibility: 'hidden',
                    pointerEvents: 'none',
                }}
            >
                <div
                    style={{ width: geo.contentW, fontFamily: theme.bodyFont }}
                >
                    {header && <div data-header="">{header}</div>}
                    {footer && (
                        <div data-footer="">
                            <MenuFooter
                                note={footer.note}
                                page={99}
                                total={99}
                                showNumbers={footer.showNumbers}
                                t={theme}
                            />
                        </div>
                    )}
                </div>
                <div style={{ width: geo.columnW, fontFamily: theme.bodyFont }}>
                    {blocks.map((b) => (
                        <div key={b.id} data-block="">
                            <BlockView block={b} gap={false} t={theme} />
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}
