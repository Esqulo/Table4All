import {
    DndContext,
    PointerSensor,
    closestCenter,
    useSensor,
    useSensors,
    type DragEndEvent,
} from '@dnd-kit/core';
import { restrictToParentElement, restrictToVerticalAxis } from '@dnd-kit/modifiers';
import {
    SortableContext,
    arrayMove,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ChevronDown, ChevronRight, GripVertical, Printer } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import MenuController from '@/actions/App/Http/Controllers/Restaurant/MenuController';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { Category, Menu, Product } from '@/types';

// ─── types ───────────────────────────────────────────────────────────────────

type PaperSize  = 'A4' | 'A5' | 'Letter' | 'Legal';
type Template   = 'classic' | 'grid' | 'elegant';
type FontChoice = 'georgia' | 'playfair' | 'lato' | 'merriweather' | 'montserrat';

type Section = { id: number; name: string; products: Product[] };

type Props = {
    menu: Menu & { user: { id: number; name: string } };
    categories: Category[];
    products: Product[];
};

// ─── fonts ───────────────────────────────────────────────────────────────────

const FONT_DEFS: Record<FontChoice, { label: string; stack: string; googleUrl?: string }> = {
    georgia:      { label: 'Georgia',         stack: "Georgia, 'Times New Roman', serif" },
    playfair:     { label: 'Playfair Display', stack: "'Playfair Display', Georgia, serif",
                    googleUrl: 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&display=swap' },
    lato:         { label: 'Lato',             stack: "'Lato', sans-serif",
                    googleUrl: 'https://fonts.googleapis.com/css2?family=Lato:wght@400;700&display=swap' },
    merriweather: { label: 'Merriweather',     stack: "'Merriweather', serif",
                    googleUrl: 'https://fonts.googleapis.com/css2?family=Merriweather:wght@300;400;700&display=swap' },
    montserrat:   { label: 'Montserrat',       stack: "'Montserrat', sans-serif",
                    googleUrl: 'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap' },
};

// ─── paper ───────────────────────────────────────────────────────────────────

const PAPER_PX: Record<PaperSize, { w: number; h: number }> = {
    A4:     { w: 794,  h: 1123 },
    A5:     { w: 559,  h: 794  },
    Letter: { w: 816,  h: 1056 },
    Legal:  { w: 816,  h: 1344 },
};

const PAPER_CSS: Record<PaperSize, string> = {
    A4: 'A4', A5: 'A5', Letter: 'letter', Legal: 'legal',
};

// Fixed max width for each page card in the preview area
const PREVIEW_MAX_W = 560;

// ─── print HTML helpers ───────────────────────────────────────────────────────

function esc(s: string) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function formatBRL(n: number) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(n);
}

function productHTML(p: Product, tpl: Template): string {
    const price = formatBRL(p.price);
    const name  = esc(p.name);
    const desc  = p.description ? `<p class="pdesc">${esc(p.description)}</p>` : '';
    const img   = p.picture_url
        ? `<img class="pimg" src="${esc(p.picture_url)}" alt="${name}">`
        : `<div class="pimg-ph"></div>`;

    if (tpl === 'grid') {
        return `<div class="product">${img}<div class="pbody"><p class="pname">${name}</p>${desc}<p class="pprice">${price}</p></div></div>`;
    }
    if (tpl === 'elegant') {
        return `<div class="product"><div class="prow"><p class="pname">${name}</p><p class="pprice">${price}</p></div>${desc}</div>`;
    }
    return `<div class="product"><div class="pinfo"><p class="pname">${name}</p>${desc}</div><p class="pprice">${price}</p></div>`;
}

function templateCSS(tpl: Template, fontStack: string): string {
    const base = `
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:${fontStack};color:#111;background:#fff;}
        .section{padding:32px 0 24px;}
        .catname{margin-bottom:20px;}
    `;
    if (tpl === 'classic') return base + `
        .catname{font-size:22px;font-weight:700;text-transform:uppercase;letter-spacing:3px;
                 border-bottom:2px solid #111;padding-bottom:8px;}
        .product{display:flex;justify-content:space-between;align-items:baseline;
                 padding:10px 0;border-bottom:1px solid #e5e5e5;gap:12px;}
        .pinfo{flex:1;}.pname{font-size:14px;font-weight:600;}
        .pdesc{font-size:11px;color:#666;margin-top:3px;}
        .pprice{font-size:14px;font-weight:700;white-space:nowrap;}
    `;
    if (tpl === 'grid') return base + `
        .catname{font-size:20px;font-weight:700;border-bottom:2px solid #111;padding-bottom:8px;}
        .products{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:4px;}
        .product{border:1px solid #ddd;border-radius:8px;overflow:hidden;}
        .pimg{width:100%;aspect-ratio:4/3;object-fit:cover;display:block;}
        .pimg-ph{width:100%;aspect-ratio:4/3;background:#f0f0f0;}
        .pbody{padding:8px 10px;}.pname{font-size:12px;font-weight:700;}
        .pdesc{font-size:10px;color:#777;margin-top:2px;}
        .pprice{font-size:13px;font-weight:700;margin-top:6px;}
    `;
    return base + `
        .catname{font-size:18px;font-weight:400;letter-spacing:5px;text-transform:uppercase;
                 text-align:center;padding-bottom:6px;border-bottom:1px solid #bbb;}
        .product{padding:10px 0;border-bottom:1px dotted #ddd;}
        .prow{display:flex;justify-content:space-between;align-items:baseline;gap:8px;}
        .pname{font-size:14px;font-weight:600;}
        .pprice{font-size:14px;font-style:italic;white-space:nowrap;}
        .pdesc{font-size:11px;color:#777;margin-top:3px;}
    `;
}

function buildPrintHTML(
    sections: Section[],
    tpl: Template,
    paper: PaperSize,
    font: FontChoice,
    title: string,
): string {
    const fontDef = FONT_DEFS[font];
    const sectHTML = sections.map((s, i) =>
        `<div class="section${i > 0 ? ' pagebreak' : ''}">
            <h2 class="catname">${esc(s.name)}</h2>
            <div class="products">${s.products.map((p) => productHTML(p, tpl)).join('')}</div>
        </div>`,
    ).join('');

    return `<!DOCTYPE html><html lang="pt-BR"><head>
<meta charset="utf-8">
<title>${esc(title)}</title>
${fontDef.googleUrl ? `<link rel="stylesheet" href="${fontDef.googleUrl}">` : ''}
<style>
@page{size:${PAPER_CSS[paper]} portrait;margin:15mm;}
.pagebreak{break-before:page;}
${templateCSS(tpl, fontDef.stack)}
</style>
</head><body>${sectHTML}</body></html>`;
}

// ─── preview section (pure inline styles — theme-agnostic, matches print) ────

type PSProps = { section: Section; tpl: Template; fontStack: string };

function PreviewSection({ section, tpl, fontStack }: PSProps) {
    const p = (n: number) => formatBRL(n);
    const root: React.CSSProperties = { fontFamily: fontStack, color: '#111', background: '#fff' };

    if (tpl === 'grid') return (
        <div style={root}>
            <h2 style={{ fontSize: 18, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 2,
                         borderBottom: '2px solid #111', paddingBottom: 6, marginBottom: 16 }}>
                {section.name}
            </h2>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 12 }}>
                {section.products.map((prod) => (
                    <div key={prod.id} style={{ border: '1px solid #ddd', borderRadius: 6, overflow: 'hidden' }}>
                        {prod.picture_url
                            ? <img src={prod.picture_url} alt={prod.name}
                                   style={{ width: '100%', aspectRatio: '4/3', objectFit: 'cover', display: 'block' }} />
                            : <div style={{ width: '100%', aspectRatio: '4/3', background: '#f0f0f0' }} />
                        }
                        <div style={{ padding: '6px 8px', fontSize: 11 }}>
                            <p style={{ fontWeight: 700, marginBottom: 2 }}>{prod.name}</p>
                            {prod.description && <p style={{ color: '#777', marginBottom: 4, lineHeight: 1.3 }}>{prod.description}</p>}
                            <p style={{ fontWeight: 700 }}>{p(prod.price)}</p>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );

    if (tpl === 'elegant') return (
        <div style={root}>
            <h2 style={{ fontSize: 16, fontWeight: 400, letterSpacing: '0.3em', textTransform: 'uppercase',
                         textAlign: 'center', borderBottom: '1px solid #bbb', paddingBottom: 6, marginBottom: 16 }}>
                {section.name}
            </h2>
            {section.products.map((prod) => (
                <div key={prod.id} style={{ padding: '8px 0', borderBottom: '1px dotted #ddd' }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 8 }}>
                        <p style={{ fontSize: 13, fontWeight: 600 }}>{prod.name}</p>
                        <p style={{ fontSize: 13, fontStyle: 'italic', whiteSpace: 'nowrap' }}>{p(prod.price)}</p>
                    </div>
                    {prod.description && <p style={{ fontSize: 11, color: '#777', marginTop: 2 }}>{prod.description}</p>}
                </div>
            ))}
        </div>
    );

    // classic
    return (
        <div style={root}>
            <h2 style={{ fontSize: 20, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 3,
                         borderBottom: '2px solid #111', paddingBottom: 8, marginBottom: 14 }}>
                {section.name}
            </h2>
            {section.products.map((prod) => (
                <div key={prod.id} style={{ display: 'flex', justifyContent: 'space-between',
                                            alignItems: 'baseline', gap: 12, padding: '9px 0',
                                            borderBottom: '1px solid #e5e5e5' }}>
                    <div style={{ flex: 1 }}>
                        <p style={{ fontSize: 13, fontWeight: 600 }}>{prod.name}</p>
                        {prod.description && <p style={{ fontSize: 11, color: '#666', marginTop: 2 }}>{prod.description}</p>}
                    </div>
                    <p style={{ fontSize: 13, fontWeight: 700, whiteSpace: 'nowrap' }}>{p(prod.price)}</p>
                </div>
            ))}
        </div>
    );
}

// ─── sortable product row ─────────────────────────────────────────────────────

function SortableProductRow({ product }: { product: Product }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
        useSortable({ id: product.id });

    return (
        <div
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition }}
            className={`flex items-center gap-2 rounded px-2 py-1.5 text-xs ${isDragging ? 'opacity-40' : 'hover:bg-muted/50'}`}
            {...attributes}
        >
            <span {...listeners} className="cursor-grab text-muted-foreground touch-none">
                <GripVertical className="h-3.5 w-3.5" />
            </span>
            <span className="flex-1 truncate">{product.name}</span>
            <span className="shrink-0 text-muted-foreground">{formatBRL(product.price)}</span>
        </div>
    );
}

// ─── sortable section row ─────────────────────────────────────────────────────

function SortableSectionRow({
    section,
    onProductsReordered,
}: {
    section: Section;
    onProductsReordered: (p: Product[]) => void;
}) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
        useSortable({ id: section.id });
    const [open, setOpen] = useState(false);

    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }));

    function handleProductDragEnd(event: DragEndEvent) {
        const { active, over } = event;
        if (!over || active.id === over.id) return;
        const from = section.products.findIndex((p) => p.id === active.id);
        const to   = section.products.findIndex((p) => p.id === over.id);
        if (from !== -1 && to !== -1) onProductsReordered(arrayMove(section.products, from, to));
    }

    return (
        <div
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition }}
            className={isDragging ? 'opacity-40' : ''}
            {...attributes}
        >
            <div className="flex items-center gap-1.5 rounded-md px-2 py-2 hover:bg-muted/50">
                <span {...listeners} className="shrink-0 cursor-grab touch-none text-muted-foreground">
                    <GripVertical className="h-4 w-4" />
                </span>
                <button
                    type="button"
                    onClick={() => setOpen((o) => !o)}
                    className="flex min-w-0 flex-1 items-center gap-1.5 text-left"
                >
                    {open
                        ? <ChevronDown  className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                        : <ChevronRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    }
                    <span className="flex-1 truncate text-sm font-medium">{section.name}</span>
                    <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                        {section.products.length}
                    </span>
                </button>
            </div>

            {open && (
                <div className="mb-1 ml-6 border-l border-border pl-2">
                    <DndContext
                        sensors={sensors}
                        collisionDetection={closestCenter}
                        modifiers={[restrictToVerticalAxis, restrictToParentElement]}
                        onDragEnd={handleProductDragEnd}
                    >
                        <SortableContext
                            items={section.products.map((p) => p.id)}
                            strategy={verticalListSortingStrategy}
                        >
                            {section.products.map((p) => (
                                <SortableProductRow key={p.id} product={p} />
                            ))}
                        </SortableContext>
                    </DndContext>
                </div>
            )}
        </div>
    );
}

// ─── template thumbnail ───────────────────────────────────────────────────────

function TemplateThumbnail({ tpl, active, onClick }: { tpl: Template; active: boolean; onClick: () => void }) {
    const { t } = useTranslation();
    const labels: Record<Template, string> = {
        classic: t('menus.print_tpl_classic'),
        grid:    t('menus.print_tpl_grid'),
        elegant: t('menus.print_tpl_elegant'),
    };
    return (
        <button
            type="button"
            onClick={onClick}
            className={`flex flex-col items-center gap-1.5 rounded-lg border-2 p-2 transition-colors
                ${active ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40'}`}
        >
            <div className="flex w-full flex-col gap-1 overflow-hidden rounded bg-muted/50 p-1.5 aspect-[3/4]">
                {tpl === 'classic' && <>
                    <div className="h-1.5 w-10 rounded-sm bg-foreground" />
                    {[0,1,2,3].map((i) => (
                        <div key={i} className="flex gap-1">
                            <div className="h-1 flex-1 rounded-sm bg-muted-foreground/40" />
                            <div className="h-1 w-6 rounded-sm bg-muted-foreground/40" />
                        </div>
                    ))}
                </>}
                {tpl === 'grid' && <>
                    <div className="mb-0.5 h-1.5 w-8 rounded-sm bg-foreground" />
                    <div className="grid flex-1 grid-cols-3 gap-0.5">
                        {[0,1,2,3,4,5].map((i) => (
                            <div key={i} className="rounded-sm bg-muted-foreground/30" />
                        ))}
                    </div>
                </>}
                {tpl === 'elegant' && <>
                    <div className="h-1 w-full rounded-sm bg-muted-foreground/30" />
                    <div className="h-1.5 w-14 self-center rounded-sm bg-foreground" />
                    <div className="h-1 w-full rounded-sm bg-muted-foreground/30" />
                    {[0,1,2].map((i) => (
                        <div key={i} className="mt-0.5 flex gap-1">
                            <div className="h-1 flex-1 rounded-sm bg-muted-foreground/40" />
                            <div className="h-1 w-5 rounded-sm bg-muted-foreground/40" />
                        </div>
                    ))}
                </>}
            </div>
            <span className="text-xs font-medium">{labels[tpl]}</span>
        </button>
    );
}

// ─── page ─────────────────────────────────────────────────────────────────────

export default function PrintMenu({ menu, categories, products }: Props) {
    const { t } = useTranslation();

    const [paper,    setPaper]    = useState<PaperSize>('A4');
    const [template, setTemplate] = useState<Template>('classic');
    const [font,     setFont]     = useState<FontChoice>('georgia');
    const [sections, setSections] = useState<Section[]>(() => {
        const catMap = new Map<number, Product[]>();
        for (const p of products) {
            const key = p.category_id ?? -1;
            if (!catMap.has(key)) catMap.set(key, []);
            catMap.get(key)!.push(p);
        }
        const result: Section[] = categories
            .filter((c) => catMap.has(c.id))
            .map((c) => ({ id: c.id, name: c.name, products: catMap.get(c.id)! }));
        const uncategorised = catMap.get(-1);
        if (uncategorised?.length)
            result.push({ id: -1, name: t('menus.print_uncategorised'), products: uncategorised });
        return result;
    });

    // Inject Google Font link for preview once per font selection
    useEffect(() => {
        const def = FONT_DEFS[font];
        if (!def.googleUrl) return;
        const id = `gf-${font}`;
        if (document.getElementById(id)) return;
        const link = Object.assign(document.createElement('link'), {
            id, rel: 'stylesheet', href: def.googleUrl,
        });
        document.head.appendChild(link);
    }, [font]);

    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }));

    function handleSectionDragEnd(event: DragEndEvent) {
        const { active, over } = event;
        if (!over || active.id === over.id) return;
        const from = sections.findIndex((s) => s.id === active.id);
        const to   = sections.findIndex((s) => s.id === over.id);
        if (from !== -1 && to !== -1) setSections(arrayMove(sections, from, to));
    }

    function updateProducts(sectionId: number, newProducts: Product[]) {
        setSections((prev) => prev.map((s) => s.id === sectionId ? { ...s, products: newProducts } : s));
    }

    const title     = `${menu.user.name} — ${menu.name}`;
    const fontStack = FONT_DEFS[font].stack;

    const printHTML = useMemo(
        () => buildPrintHTML(sections, template, paper, font, title),
        [sections, template, paper, font, title],
    );

    // Fixed scale so the preview card fits in the panel regardless of window size
    const { w: pw, h: ph } = PAPER_PX[paper];
    const scale   = Math.min(1, PREVIEW_MAX_W / pw);
    const scaledW = Math.round(pw * scale);
    const scaledH = Math.round(ph * scale);

    function handlePrint() {
        const win = window.open('', '_blank', 'width=900,height=700');
        if (!win) return;
        win.document.write(printHTML);
        win.document.close();
        win.focus();
        setTimeout(() => { win.print(); win.close(); }, 500);
    }

    return (
        <>
            <Head title={`${t('menus.print_heading')} — ${menu.name}`} />

            <div className="flex h-screen flex-col bg-background">
                {/* top bar */}
                <header className="flex shrink-0 items-center gap-3 border-b border-border px-4 py-3">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={MenuController.index.url()}>
                            <ArrowLeft className="mr-1.5 h-4 w-4" />
                            {t('menus.breadcrumb_index')}
                        </Link>
                    </Button>
                    <span className="text-muted-foreground">/</span>
                    <h1 className="truncate text-sm font-semibold">
                        {menu.name} — {t('menus.print_heading')}
                    </h1>
                    <Button size="sm" className="ml-auto" onClick={handlePrint}>
                        <Printer className="mr-1.5 h-4 w-4" />
                        {t('menus.print_button')}
                    </Button>
                </header>

                <div className="flex flex-1 overflow-hidden">
                    {/* ── left panel ── */}
                    <aside className="w-64 shrink-0 overflow-y-auto border-r border-border xl:w-72">
                        <div className="space-y-6 p-4">

                            {/* paper size */}
                            <section className="space-y-2">
                                <Label className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    {t('menus.print_paper')}
                                </Label>
                                <div className="grid grid-cols-2 gap-1.5">
                                    {(['A4','A5','Letter','Legal'] as PaperSize[]).map((s) => (
                                        <button
                                            key={s}
                                            type="button"
                                            onClick={() => setPaper(s)}
                                            className={`rounded-md border px-3 py-1.5 text-xs font-medium transition-colors
                                                ${paper === s
                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                    : 'border-border hover:border-primary/40'}`}
                                        >
                                            {s}
                                        </button>
                                    ))}
                                </div>
                            </section>

                            {/* template */}
                            <section className="space-y-2">
                                <Label className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    {t('menus.print_template')}
                                </Label>
                                <div className="grid grid-cols-3 gap-1.5">
                                    {(['classic','grid','elegant'] as Template[]).map((tpl) => (
                                        <TemplateThumbnail
                                            key={tpl}
                                            tpl={tpl}
                                            active={template === tpl}
                                            onClick={() => setTemplate(tpl)}
                                        />
                                    ))}
                                </div>
                            </section>

                            {/* font */}
                            <section className="space-y-2">
                                <Label className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    {t('menus.print_font')}
                                </Label>
                                <div className="flex flex-col gap-1">
                                    {(Object.keys(FONT_DEFS) as FontChoice[]).map((f) => (
                                        <button
                                            key={f}
                                            type="button"
                                            onClick={() => setFont(f)}
                                            className={`rounded-md border px-3 py-2 text-sm text-left transition-colors
                                                ${font === f
                                                    ? 'border-primary bg-primary/5'
                                                    : 'border-border hover:border-primary/40'}`}
                                            style={{ fontFamily: FONT_DEFS[f].stack }}
                                        >
                                            {FONT_DEFS[f].label}
                                        </button>
                                    ))}
                                </div>
                            </section>

                            {/* page order */}
                            <section className="space-y-2">
                                <Label className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    {t('menus.print_order')}
                                </Label>
                                <p className="text-xs text-muted-foreground">{t('menus.print_order_hint')}</p>

                                {sections.length === 0 ? (
                                    <p className="py-2 text-xs italic text-muted-foreground">
                                        {t('menus.print_no_products')}
                                    </p>
                                ) : (
                                    <DndContext
                                        sensors={sensors}
                                        collisionDetection={closestCenter}
                                        modifiers={[restrictToVerticalAxis, restrictToParentElement]}
                                        onDragEnd={handleSectionDragEnd}
                                    >
                                        <SortableContext
                                            items={sections.map((s) => s.id)}
                                            strategy={verticalListSortingStrategy}
                                        >
                                            {sections.map((section) => (
                                                <SortableSectionRow
                                                    key={section.id}
                                                    section={section}
                                                    onProductsReordered={(p) => updateProducts(section.id, p)}
                                                />
                                            ))}
                                        </SortableContext>
                                    </DndContext>
                                )}
                            </section>
                        </div>
                    </aside>

                    {/* ── preview area ── */}
                    <main className="flex flex-1 flex-col items-center gap-8 overflow-y-auto bg-muted/30 px-4 py-8">
                        {sections.length === 0 ? (
                            <p className="mt-16 text-sm text-muted-foreground">
                                {t('menus.print_no_products')}
                            </p>
                        ) : (
                            sections.map((section, i) => (
                                <div key={section.id} className="flex flex-col items-center gap-2">
                                    <span className="text-xs text-muted-foreground">
                                        {t('menus.print_page')} {i + 1}
                                    </span>
                                    {/*
                                     * Outer: clipping container sized to the scaled dimensions.
                                     * Inner: full paper size, absolutely positioned, scaled from top-left.
                                     * This is the correct pattern for CSS-transform scale previews.
                                     */}
                                    <div
                                        className="shadow-lg"
                                        style={{
                                            width: scaledW,
                                            height: scaledH,
                                            overflow: 'hidden',
                                            position: 'relative',
                                            background: '#fff',
                                        }}
                                    >
                                        <div
                                            style={{
                                                position: 'absolute',
                                                top: 0,
                                                left: 0,
                                                width: pw,
                                                height: ph,
                                                padding: '57px 57px', // ≈ 15mm at 96dpi
                                                transform: `scale(${scale})`,
                                                transformOrigin: 'top left',
                                            }}
                                        >
                                            <PreviewSection
                                                section={section}
                                                tpl={template}
                                                fontStack={fontStack}
                                            />
                                        </div>
                                    </div>
                                </div>
                            ))
                        )}
                    </main>
                </div>
            </div>
        </>
    );
}
