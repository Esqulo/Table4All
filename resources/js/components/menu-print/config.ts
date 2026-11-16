// Print menu editor configuration. The ids here are validated on the server
// by App\Http\Controllers\Restaurant\MenuController — keep both lists in sync.

export type PaperSize = 'A4' | 'A5' | 'Letter' | 'Legal';
export type TemplateId =
    | 'classic'
    | 'grid'
    | 'elegant'
    | 'compact'
    | 'photo'
    | 'chalkboard'
    | 'modern'
    | 'minimal';
export type ItemStyle = 'list' | 'leaders' | 'elegant' | 'photo' | 'grid';
export type HeadingStyle =
    | 'underline'
    | 'centered'
    | 'band'
    | 'bar'
    | 'minimal'
    | 'chalk';
export type FontId =
    | 'georgia'
    | 'arial'
    | 'playfair'
    | 'cormorant'
    | 'lora'
    | 'merriweather'
    | 'ebgaramond'
    | 'robotoslab'
    | 'abril'
    | 'lato'
    | 'montserrat'
    | 'poppins'
    | 'raleway'
    | 'nunito'
    | 'josefin'
    | 'oswald'
    | 'bebas'
    | 'pacifico'
    | 'dancing'
    | 'caveat'
    | 'patrick'
    | 'amatic';
export type FontCategory = 'serif' | 'sans' | 'display' | 'handwritten';

/** One category block of the menu (key = category id, -1 = uncategorised). */
export type SectionLayout = {
    key: number;
    title: string | null;
    style: ItemStyle | null;
    hidden: boolean;
    products: number[];
};

/** What gets saved on the menu (snake_case, mirrors the server validation). */
export type MenuLayout = {
    template: TemplateId;
    item_style: ItemStyle;
    paper: PaperSize;
    landscape: boolean;
    columns: 1 | 2 | 3;
    heading_font: FontId;
    body_font: FontId;
    accent: string;
    scale: number;
    grouped: boolean;
    show_descriptions: boolean;
    show_images: boolean;
    section_page_break: boolean;
    show_header: boolean;
    show_page_numbers: boolean;
    title: string | null;
    subtitle: string | null;
    footer: string | null;
    sections: SectionLayout[];
    flat_order: number[];
    hidden_products: number[];
};

// ─── fonts ───────────────────────────────────────────────────────────────────

type FontDef = {
    label: string;
    stack: string;
    category: FontCategory;
    google?: string;
};

export const FONTS: Record<FontId, FontDef> = {
    georgia: {
        label: 'Georgia',
        category: 'serif',
        stack: "Georgia, 'Times New Roman', serif",
    },
    playfair: {
        label: 'Playfair Display',
        category: 'serif',
        stack: "'Playfair Display', Georgia, serif",
        google: 'Playfair+Display:ital,wght@0,400;0,600;0,700;1,400',
    },
    cormorant: {
        label: 'Cormorant Garamond',
        category: 'serif',
        stack: "'Cormorant Garamond', Georgia, serif",
        google: 'Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400',
    },
    lora: {
        label: 'Lora',
        category: 'serif',
        stack: "'Lora', Georgia, serif",
        google: 'Lora:ital,wght@0,400;0,600;0,700;1,400',
    },
    merriweather: {
        label: 'Merriweather',
        category: 'serif',
        stack: "'Merriweather', Georgia, serif",
        google: 'Merriweather:ital,wght@0,300;0,400;0,700;1,400',
    },
    ebgaramond: {
        label: 'EB Garamond',
        category: 'serif',
        stack: "'EB Garamond', Georgia, serif",
        google: 'EB+Garamond:ital,wght@0,400;0,600;0,700;1,400',
    },
    robotoslab: {
        label: 'Roboto Slab',
        category: 'serif',
        stack: "'Roboto Slab', Georgia, serif",
        google: 'Roboto+Slab:wght@400;600;700',
    },
    arial: {
        label: 'Arial',
        category: 'sans',
        stack: 'Arial, Helvetica, sans-serif',
    },
    lato: {
        label: 'Lato',
        category: 'sans',
        stack: "'Lato', Arial, sans-serif",
        google: 'Lato:ital,wght@0,400;0,700;1,400',
    },
    montserrat: {
        label: 'Montserrat',
        category: 'sans',
        stack: "'Montserrat', Arial, sans-serif",
        google: 'Montserrat:ital,wght@0,400;0,600;0,700;1,400',
    },
    poppins: {
        label: 'Poppins',
        category: 'sans',
        stack: "'Poppins', Arial, sans-serif",
        google: 'Poppins:ital,wght@0,400;0,600;0,700;1,400',
    },
    raleway: {
        label: 'Raleway',
        category: 'sans',
        stack: "'Raleway', Arial, sans-serif",
        google: 'Raleway:ital,wght@0,400;0,600;0,700;1,400',
    },
    nunito: {
        label: 'Nunito',
        category: 'sans',
        stack: "'Nunito', Arial, sans-serif",
        google: 'Nunito:ital,wght@0,400;0,600;0,700;1,400',
    },
    josefin: {
        label: 'Josefin Sans',
        category: 'sans',
        stack: "'Josefin Sans', Arial, sans-serif",
        google: 'Josefin+Sans:ital,wght@0,300;0,400;0,600;0,700;1,400',
    },
    oswald: {
        label: 'Oswald',
        category: 'display',
        stack: "'Oswald', 'Arial Narrow', sans-serif",
        google: 'Oswald:wght@400;600;700',
    },
    bebas: {
        label: 'Bebas Neue',
        category: 'display',
        stack: "'Bebas Neue', 'Arial Narrow', sans-serif",
        google: 'Bebas+Neue',
    },
    abril: {
        label: 'Abril Fatface',
        category: 'display',
        stack: "'Abril Fatface', Georgia, serif",
        google: 'Abril+Fatface',
    },
    pacifico: {
        label: 'Pacifico',
        category: 'handwritten',
        stack: "'Pacifico', cursive",
        google: 'Pacifico',
    },
    dancing: {
        label: 'Dancing Script',
        category: 'handwritten',
        stack: "'Dancing Script', cursive",
        google: 'Dancing+Script:wght@400;700',
    },
    caveat: {
        label: 'Caveat',
        category: 'handwritten',
        stack: "'Caveat', cursive",
        google: 'Caveat:wght@400;700',
    },
    patrick: {
        label: 'Patrick Hand',
        category: 'handwritten',
        stack: "'Patrick Hand', cursive",
        google: 'Patrick+Hand',
    },
    amatic: {
        label: 'Amatic SC',
        category: 'handwritten',
        stack: "'Amatic SC', cursive",
        google: 'Amatic+SC:wght@400;700',
    },
};

export const FONT_CATEGORIES: FontCategory[] = [
    'serif',
    'sans',
    'display',
    'handwritten',
];

/** One stylesheet with every font the given ids need (null when all are system fonts). */
export function googleFontsUrl(ids: FontId[]): string | null {
    const families = [...new Set(ids)]
        .map((id) => FONTS[id].google)
        .filter(Boolean);

    return families.length
        ? `https://fonts.googleapis.com/css2?${families.map((f) => `family=${f}`).join('&')}&display=swap`
        : null;
}

// ─── paper ───────────────────────────────────────────────────────────────────

export const PAPERS: Record<PaperSize, { w: number; h: number; css: string }> =
    {
        A4: { w: 210, h: 297, css: 'A4' },
        A5: { w: 148, h: 210, css: 'A5' },
        Letter: { w: 215.9, h: 279.4, css: 'letter' },
        Legal: { w: 215.9, h: 355.6, css: 'legal' },
    };

export const MM = 96 / 25.4; // CSS px per millimetre

// ─── templates ───────────────────────────────────────────────────────────────

export type Palette = {
    bg: string;
    fg: string;
    muted: string;
    line: string;
    card: string;
};

const LIGHT: Palette = {
    bg: '#ffffff',
    fg: '#151515',
    muted: '#666666',
    line: '#e2e2e2',
    card: '#f5f5f5',
};
const CREAM: Palette = {
    bg: '#fbf8f1',
    fg: '#2b2620',
    muted: '#7a6f60',
    line: '#dcd0bb',
    card: '#f2eadb',
};
const CHALK: Palette = {
    bg: '#1f2a24',
    fg: '#f4f1e8',
    muted: '#c9c4b5',
    line: 'rgba(244,241,232,0.28)',
    card: 'rgba(255,255,255,0.07)',
};

export type TemplateDef = {
    palette: Palette;
    heading: HeadingStyle;
    /** Defaults applied when the template is picked. */
    item: ItemStyle;
    columns: 1 | 2 | 3;
    headingFont: FontId;
    bodyFont: FontId;
    accent: string;
    scale: number;
};

export const TEMPLATES: Record<TemplateId, TemplateDef> = {
    classic: {
        palette: LIGHT,
        heading: 'underline',
        item: 'list',
        columns: 1,
        headingFont: 'playfair',
        bodyFont: 'georgia',
        accent: '#151515',
        scale: 1,
    },
    grid: {
        palette: LIGHT,
        heading: 'underline',
        item: 'grid',
        columns: 1,
        headingFont: 'montserrat',
        bodyFont: 'lato',
        accent: '#151515',
        scale: 1,
    },
    elegant: {
        palette: CREAM,
        heading: 'centered',
        item: 'elegant',
        columns: 1,
        headingFont: 'cormorant',
        bodyFont: 'lora',
        accent: '#8a6d3b',
        scale: 1,
    },
    compact: {
        palette: LIGHT,
        heading: 'minimal',
        item: 'leaders',
        columns: 2,
        headingFont: 'oswald',
        bodyFont: 'lato',
        accent: '#151515',
        scale: 0.9,
    },
    photo: {
        palette: LIGHT,
        heading: 'band',
        item: 'photo',
        columns: 1,
        headingFont: 'poppins',
        bodyFont: 'poppins',
        accent: '#22B573',
        scale: 1,
    },
    chalkboard: {
        palette: CHALK,
        heading: 'chalk',
        item: 'leaders',
        columns: 2,
        headingFont: 'caveat',
        bodyFont: 'patrick',
        accent: '#f2c94c',
        scale: 1.05,
    },
    modern: {
        palette: LIGHT,
        heading: 'bar',
        item: 'list',
        columns: 2,
        headingFont: 'bebas',
        bodyFont: 'nunito',
        accent: '#e4572e',
        scale: 1,
    },
    minimal: {
        palette: LIGHT,
        heading: 'minimal',
        item: 'elegant',
        columns: 1,
        headingFont: 'josefin',
        bodyFont: 'josefin',
        accent: '#9a9a9a',
        scale: 0.95,
    },
};

export const TEMPLATE_IDS = Object.keys(TEMPLATES) as TemplateId[];
export const ITEM_STYLES: ItemStyle[] = [
    'list',
    'leaders',
    'elegant',
    'photo',
    'grid',
];

export function defaultLayout(title: string): MenuLayout {
    const tpl = TEMPLATES.classic;

    return {
        template: 'classic',
        item_style: tpl.item,
        paper: 'A4',
        landscape: false,
        columns: tpl.columns,
        heading_font: tpl.headingFont,
        body_font: tpl.bodyFont,
        accent: tpl.accent,
        scale: tpl.scale,
        grouped: true,
        show_descriptions: true,
        show_images: true,
        section_page_break: false,
        show_header: true,
        show_page_numbers: true,
        title,
        subtitle: null,
        footer: null,
        sections: [],
        flat_order: [],
        hidden_products: [],
    };
}

/** Picking a template applies its look; content and texts stay untouched. */
export function applyTemplate(layout: MenuLayout, id: TemplateId): MenuLayout {
    const tpl = TEMPLATES[id];

    return {
        ...layout,
        template: id,
        item_style: tpl.item,
        columns: tpl.columns,
        heading_font: tpl.headingFont,
        body_font: tpl.bodyFont,
        accent: tpl.accent,
        scale: tpl.scale,
    };
}
