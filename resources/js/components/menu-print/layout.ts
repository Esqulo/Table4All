import type { Category, Product } from '@/types';
import {
    defaultLayout,
    ITEM_STYLES,
    LOGO_POSITIONS,
    LOGO_SIZES,
    MM,
    PAPERS,
    TEMPLATE_IDS,
    TEMPLATES,
} from './config';
import type { ItemStyle, MenuLayout, SectionLayout } from './config';

export const UNCATEGORISED = -1;

// ─── saved layout ↔ current catalogue ────────────────────────────────────────

/**
 * Merges a saved layout with the catalogue as it is now: products and
 * categories created since are appended, deleted ones dropped, unknown
 * values fall back to the defaults.
 */
export function reconcile(
    saved: Partial<MenuLayout> | null,
    categories: Category[],
    products: Product[],
    title: string,
): MenuLayout {
    const base = defaultLayout(title);
    const layout: MenuLayout = { ...base, ...(saved ?? {}) };

    if (!TEMPLATE_IDS.includes(layout.template)) {
        layout.template = base.template;
    }

    if (!ITEM_STYLES.includes(layout.item_style)) {
        layout.item_style = TEMPLATES[layout.template].item;
    }

    if (!(layout.logo_size in LOGO_SIZES)) {
        layout.logo_size = base.logo_size;
    }

    if (!LOGO_POSITIONS.includes(layout.logo_position)) {
        layout.logo_position = base.logo_position;
    }

    const byCategory = new Map<number, number[]>();

    for (const p of products) {
        const key = p.category_id ?? UNCATEGORISED;

        if (!byCategory.has(key)) {
            byCategory.set(key, []);
        }

        byCategory.get(key)!.push(p.id);
    }

    const currentKeys = [
        ...categories.filter((c) => byCategory.has(c.id)).map((c) => c.id),
        ...(byCategory.has(UNCATEGORISED) ? [UNCATEGORISED] : []),
    ];

    const savedSections = new Map(
        (saved?.sections ?? []).map((s) => [s.key, s]),
    );
    const orderedKeys = [
        ...(saved?.sections ?? [])
            .map((s) => s.key)
            .filter((k) => byCategory.has(k)),
        ...currentKeys.filter((k) => !savedSections.has(k)),
    ];

    layout.sections = orderedKeys.map((key): SectionLayout => {
        const prev = savedSections.get(key);
        const ids = byCategory.get(key)!;

        return {
            key,
            title: prev?.title ?? null,
            style:
                prev?.style && ITEM_STYLES.includes(prev.style)
                    ? prev.style
                    : null,
            hidden: prev?.hidden ?? false,
            products: mergeOrder(prev?.products ?? [], ids),
        };
    });

    const allIds = layout.sections.flatMap((s) => s.products);
    layout.flat_order = mergeOrder(saved?.flat_order ?? [], allIds);

    const existing = new Set(allIds);
    layout.hidden_products = (saved?.hidden_products ?? []).filter((id) =>
        existing.has(id),
    );

    return layout;
}

/** Keeps the saved order of ids that still exist and appends the new ones. */
function mergeOrder(saved: number[], current: number[]): number[] {
    const set = new Set(current);
    const kept = saved.filter((id) => set.has(id));
    const keptSet = new Set(kept);

    return [...kept, ...current.filter((id) => !keptSet.has(id))];
}

// ─── visible content ─────────────────────────────────────────────────────────

export type ContentSection = {
    key: number;
    title: string | null; // null when not grouped
    style: ItemStyle;
    products: Product[];
};

export function visibleSections(
    layout: MenuLayout,
    categories: Category[],
    products: Product[],
    uncategorisedLabel: string,
): ContentSection[] {
    const byId = new Map(products.map((p) => [p.id, p]));
    const hidden = new Set(layout.hidden_products);
    const pick = (ids: number[]) =>
        ids
            .filter((id) => !hidden.has(id))
            .map((id) => byId.get(id))
            .filter((p): p is Product => !!p);

    if (!layout.grouped) {
        const visible = pick(layout.flat_order);

        return visible.length
            ? [
                  {
                      key: 0,
                      title: null,
                      style: layout.item_style,
                      products: visible,
                  },
              ]
            : [];
    }

    const names = new Map(categories.map((c) => [c.id, c.name]));

    return layout.sections
        .filter((s) => !s.hidden)
        .map((s) => ({
            key: s.key,
            title:
                s.title?.trim() ||
                (s.key === UNCATEGORISED
                    ? uncategorisedLabel
                    : (names.get(s.key) ?? '')),
            style: s.style ?? layout.item_style,
            products: pick(s.products),
        }))
        .filter((s) => s.products.length > 0);
}

// ─── geometry ────────────────────────────────────────────────────────────────

export const COLUMN_GAP = 28;

export type Geometry = {
    pageW: number;
    pageH: number;
    margin: number;
    contentW: number;
    contentH: number;
    columnW: number;
    columns: number;
};

export function geometry(layout: MenuLayout): Geometry {
    const paper = PAPERS[layout.paper];
    const wMm = layout.landscape ? paper.h : paper.w;
    const hMm = layout.landscape ? paper.w : paper.h;
    const marginMm =
        layout.paper === 'A3' ? 18 : layout.paper === 'A5' ? 10 : 14;

    const pageW = wMm * MM;
    const pageH = hMm * MM;
    const margin = marginMm * MM;
    const contentW = pageW - margin * 2;
    // A couple of pixels of slack absorbs sub-pixel rounding between preview and print.
    const contentH = Math.floor(pageH - margin * 2) - 2;
    const columns = layout.columns;
    const columnW = (contentW - COLUMN_GAP * (columns - 1)) / columns;

    return { pageW, pageH, margin, contentW, contentH, columnW, columns };
}

/** Grid cards per row: as many as fit at ~112px each (max 4) in one column. */
export function cardsPerRow(columnW: number, scale: number): number {
    return Math.max(1, Math.min(4, Math.floor(columnW / (112 * scale))));
}

// ─── blocks ──────────────────────────────────────────────────────────────────

/** The unit of pagination: never split across columns or pages. */
export type Block =
    | { kind: 'heading'; id: string; title: string; first: boolean }
    | {
          kind: 'items';
          id: string;
          style: ItemStyle;
          products: Product[];
          last: boolean;
      };

export function buildBlocks(
    sections: ContentSection[],
    perRow: number,
): Block[] {
    const blocks: Block[] = [];

    sections.forEach((section, si) => {
        if (section.title !== null) {
            blocks.push({
                kind: 'heading',
                id: `h${section.key}`,
                title: section.title,
                first: si === 0,
            });
        }

        const chunk = section.style === 'grid' ? perRow : 1;

        for (let i = 0; i < section.products.length; i += chunk) {
            const slice = section.products.slice(i, i + chunk);
            blocks.push({
                kind: 'items',
                id: `s${section.key}-${slice[0].id}`,
                style: section.style,
                products: slice,
                last: i + chunk >= section.products.length,
            });
        }
    });

    return blocks;
}

// ─── pagination ──────────────────────────────────────────────────────────────

export type PageLayout = { columns: number[][] }; // block indices per column

type PaginateOptions = {
    heights: number[];
    /** Extra space above a heading that doesn't open a column. */
    sectionGap: number;
    columns: number;
    capacity: number;
    firstPageCapacity: number;
    sectionPageBreak: boolean;
};

/**
 * Greedy column-by-column packing of measured blocks. A heading always moves
 * together with the first row of its section, so a category title is never
 * left alone at the bottom of a column.
 */
export function paginate(blocks: Block[], o: PaginateOptions): PageLayout[] {
    const pages: PageLayout[] = [];
    let page: PageLayout = { columns: [[]] };
    let col = 0;
    let used = 0;
    pages.push(page);

    const capacity = () =>
        pages.length === 1 ? o.firstPageCapacity : o.capacity;
    const pageIsEmpty = () => page.columns.every((c) => c.length === 0);

    const newPage = () => {
        page = { columns: [[]] };
        pages.push(page);
        col = 0;
        used = 0;
    };

    const nextColumn = () => {
        if (col + 1 < o.columns) {
            col += 1;
            page.columns[col] = [];
            used = 0;
        } else {
            newPage();
        }
    };

    const heightAt = (i: number, atTop: boolean) =>
        o.heights[i] +
        (blocks[i].kind === 'heading' && !atTop ? o.sectionGap : 0);

    blocks.forEach((block, i) => {
        if (
            block.kind === 'heading' &&
            o.sectionPageBreak &&
            !block.first &&
            !pageIsEmpty()
        ) {
            newPage();
        }

        let needed = heightAt(i, used === 0);

        if (block.kind === 'heading' && i + 1 < blocks.length) {
            needed += o.heights[i + 1];
        }

        if (used > 0 && used + needed > capacity()) {
            nextColumn();
        }

        page.columns[col].push(i);
        used += heightAt(i, page.columns[col].length === 1);
    });

    // Columns that were never reached still need to exist for the flex layout.
    for (const p of pages) {
        for (let c = 0; c < o.columns; c++) {
            p.columns[c] ??= [];
        }
    }

    return pages;
}
