// Menu page rendering. Everything uses inline styles so the exact same DOM
// can be measured, previewed and copied into the print frame (WYSIWYG).
import type { CSSProperties, ReactNode } from 'react';
import type { Product } from '@/types';
import type { HeadingStyle, ItemStyle, Palette } from './config';
import { COLUMN_GAP } from './layout';
import type { Block, Geometry, PageLayout } from './layout';

export type Theme = {
    palette: Palette;
    heading: HeadingStyle;
    accent: string;
    headingFont: string;
    bodyFont: string;
    scale: number;
    showDescriptions: boolean;
    showImages: boolean;
    perRow: number;
    price: (p: Product) => string;
};

export const SECTION_GAP = 22;

const px = (n: number, t: Theme) => Math.round(n * t.scale * 10) / 10;

/** Readable text colour on top of the accent colour. */
function onAccent(hex: string): string {
    const n = parseInt(hex.slice(1), 16);
    const [r, g, b] = [(n >> 16) & 255, (n >> 8) & 255, n & 255];

    return 0.299 * r + 0.587 * g + 0.114 * b > 160 ? '#151515' : '#ffffff';
}

const reset: CSSProperties = { margin: 0, padding: 0 };

// ─── header / footer ─────────────────────────────────────────────────────────

export function MenuHeader({
    title,
    subtitle,
    t,
}: {
    title: string;
    subtitle: string;
    t: Theme;
}) {
    const centered = t.heading !== 'bar' && t.heading !== 'underline';

    return (
        <div
            style={{
                display: 'flow-root',
                paddingBottom: px(20, t),
                textAlign: centered ? 'center' : 'left',
            }}
        >
            {subtitle && (
                <p
                    style={{
                        ...reset,
                        fontFamily: t.bodyFont,
                        fontSize: px(11, t),
                        letterSpacing: '0.25em',
                        textTransform: 'uppercase',
                        color: t.palette.muted,
                        marginBottom: px(4, t),
                    }}
                >
                    {subtitle}
                </p>
            )}
            <h1
                style={{
                    ...reset,
                    fontFamily: t.headingFont,
                    fontSize: px(t.heading === 'chalk' ? 46 : 36, t),
                    lineHeight: 1.1,
                    fontWeight: 700,
                    color: t.heading === 'chalk' ? t.accent : t.palette.fg,
                    overflowWrap: 'anywhere',
                }}
            >
                {title}
            </h1>
            <div
                style={{
                    height: t.heading === 'band' ? 4 : 2,
                    width: centered ? px(64, t) : '100%',
                    margin: `${px(10, t)}px ${centered ? 'auto' : '0'} 0`,
                    background:
                        t.heading === 'minimal' || t.heading === 'centered'
                            ? t.palette.line
                            : t.accent,
                }}
            />
        </div>
    );
}

export function MenuFooter({
    note,
    page,
    total,
    showNumbers,
    t,
}: {
    note: string;
    page: number;
    total: number;
    showNumbers: boolean;
    t: Theme;
}) {
    return (
        <div
            style={{
                display: 'flex',
                alignItems: 'flex-end',
                justifyContent: 'space-between',
                gap: 16,
                paddingTop: px(10, t),
                fontFamily: t.bodyFont,
                fontSize: px(9.5, t),
                color: t.palette.muted,
            }}
        >
            <p style={{ ...reset, flex: 1, overflowWrap: 'anywhere' }}>
                {note}
            </p>
            {showNumbers && (
                <p style={{ ...reset, whiteSpace: 'nowrap' }}>
                    {page} / {total}
                </p>
            )}
        </div>
    );
}

// ─── section heading ─────────────────────────────────────────────────────────

export function SectionHeading({
    title,
    gap,
    t,
}: {
    title: string;
    gap: boolean;
    t: Theme;
}) {
    const wrap: CSSProperties = {
        display: 'flow-root',
        paddingTop: gap ? px(SECTION_GAP, t) : 0,
    };
    const font: CSSProperties = {
        ...reset,
        fontFamily: t.headingFont,
        overflowWrap: 'anywhere',
    };
    const { palette: c, accent } = t;

    let inner: ReactNode;

    switch (t.heading) {
        case 'centered':
            inner = (
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: px(12, t),
                        marginBottom: px(10, t),
                    }}
                >
                    <span style={{ flex: 1, height: 1, background: c.line }} />
                    <h2
                        style={{
                            ...font,
                            fontSize: px(19, t),
                            fontWeight: 600,
                            letterSpacing: '0.28em',
                            textTransform: 'uppercase',
                            color: accent,
                            textAlign: 'center',
                        }}
                    >
                        {title}
                    </h2>
                    <span style={{ flex: 1, height: 1, background: c.line }} />
                </div>
            );
            break;
        case 'band':
            inner = (
                <h2
                    style={{
                        ...font,
                        fontSize: px(15, t),
                        fontWeight: 700,
                        letterSpacing: '0.08em',
                        textTransform: 'uppercase',
                        background: accent,
                        color: onAccent(accent),
                        padding: `${px(6, t)}px ${px(12, t)}px`,
                        borderRadius: 4,
                        marginBottom: px(8, t),
                    }}
                >
                    {title}
                </h2>
            );
            break;
        case 'bar':
            inner = (
                <h2
                    style={{
                        ...font,
                        fontSize: px(26, t),
                        lineHeight: 1.1,
                        fontWeight: 700,
                        letterSpacing: '0.04em',
                        textTransform: 'uppercase',
                        color: c.fg,
                        borderLeft: `${px(6, t)}px solid ${accent}`,
                        paddingLeft: px(10, t),
                        marginBottom: px(8, t),
                    }}
                >
                    {title}
                </h2>
            );
            break;
        case 'minimal':
            inner = (
                <h2
                    style={{
                        ...font,
                        fontSize: px(12, t),
                        fontWeight: 600,
                        letterSpacing: '0.32em',
                        textTransform: 'uppercase',
                        color: accent === '#151515' ? c.fg : accent,
                        borderBottom: `1px solid ${c.line}`,
                        paddingBottom: px(5, t),
                        marginBottom: px(8, t),
                    }}
                >
                    {title}
                </h2>
            );
            break;
        case 'chalk':
            inner = (
                <div style={{ textAlign: 'center', marginBottom: px(8, t) }}>
                    <h2
                        style={{
                            ...font,
                            display: 'inline-block',
                            fontSize: px(30, t),
                            lineHeight: 1.15,
                            fontWeight: 700,
                            color: accent,
                            borderBottom: `2px dashed ${c.line}`,
                            padding: `0 ${px(10, t)}px ${px(2, t)}px`,
                        }}
                    >
                        {title}
                    </h2>
                </div>
            );
            break;
        default: // underline
            inner = (
                <h2
                    style={{
                        ...font,
                        fontSize: px(20, t),
                        fontWeight: 700,
                        letterSpacing: '0.12em',
                        textTransform: 'uppercase',
                        color: c.fg,
                        borderBottom: `2px solid ${accent}`,
                        paddingBottom: px(6, t),
                        marginBottom: px(8, t),
                    }}
                >
                    {title}
                </h2>
            );
    }

    return <div style={wrap}>{inner}</div>;
}

// ─── products ────────────────────────────────────────────────────────────────

function Desc({
    text,
    t,
    clamp,
    italic,
}: {
    text: string | null;
    t: Theme;
    clamp?: number;
    italic?: boolean;
}) {
    if (!t.showDescriptions || !text) {
        return null;
    }

    return (
        <p
            style={{
                ...reset,
                fontSize: px(10.5, t),
                lineHeight: 1.35,
                color: t.palette.muted,
                marginTop: px(2, t),
                fontStyle: italic ? 'italic' : 'normal',
                overflowWrap: 'anywhere',
                ...(clamp
                    ? {
                          display: '-webkit-box',
                          WebkitLineClamp: clamp,
                          WebkitBoxOrient: 'vertical',
                          overflow: 'hidden',
                      }
                    : {}),
            }}
        >
            {text}
        </p>
    );
}

function Thumb({
    product,
    size,
    t,
}: {
    product: Product;
    size: number;
    t: Theme;
}) {
    const box: CSSProperties = {
        width: px(size, t),
        height: px(size, t),
        borderRadius: 6,
        flexShrink: 0,
        objectFit: 'cover',
        display: 'block',
        background: t.palette.card,
    };

    return product.picture_url ? (
        <img src={product.picture_url} alt="" style={box} />
    ) : (
        <div style={box} />
    );
}

export function ProductItems({
    block,
    last,
    t,
}: {
    block: Extract<Block, { kind: 'items' }>;
    last: boolean;
    t: Theme;
}) {
    const { palette: c, accent } = t;
    const name: CSSProperties = {
        ...reset,
        fontSize: px(13, t),
        fontWeight: 600,
        lineHeight: 1.3,
        color: c.fg,
        overflowWrap: 'anywhere',
    };
    const price: CSSProperties = {
        ...reset,
        fontSize: px(13, t),
        fontWeight: 700,
        whiteSpace: 'nowrap',
        color: c.fg,
    };
    const bottom = last ? px(4, t) : 0;

    if (block.style === 'grid') {
        return (
            <div
                style={{
                    display: 'flex',
                    gap: px(10, t),
                    paddingBottom: px(10, t),
                }}
            >
                {Array.from({ length: t.perRow }, (_, i) => {
                    const p = block.products[i];

                    if (!p) {
                        return (
                            <div
                                key={`empty-${i}`}
                                style={{ flex: 1, minWidth: 0 }}
                            />
                        );
                    }

                    return (
                        <div
                            key={p.id}
                            style={{
                                flex: 1,
                                minWidth: 0,
                                border: `1px solid ${c.line}`,
                                borderRadius: 8,
                                overflow: 'hidden',
                                background:
                                    c.card === '#f5f5f5' ? c.bg : c.card,
                                display: 'flex',
                                flexDirection: 'column',
                            }}
                        >
                            {t.showImages &&
                                (p.picture_url ? (
                                    <img
                                        src={p.picture_url}
                                        alt=""
                                        style={{
                                            width: '100%',
                                            aspectRatio: '4 / 3',
                                            objectFit: 'cover',
                                            display: 'block',
                                        }}
                                    />
                                ) : (
                                    <div
                                        style={{
                                            width: '100%',
                                            aspectRatio: '4 / 3',
                                            background: c.card,
                                        }}
                                    />
                                ))}
                            <div
                                style={{
                                    padding: `${px(7, t)}px ${px(9, t)}px ${px(8, t)}px`,
                                    flex: 1,
                                    display: 'flex',
                                    flexDirection: 'column',
                                }}
                            >
                                <p style={{ ...name, fontSize: px(12, t) }}>
                                    {p.name}
                                </p>
                                <Desc text={p.description} t={t} clamp={3} />
                                <p
                                    style={{
                                        ...price,
                                        color:
                                            accent === '#151515'
                                                ? c.fg
                                                : accent,
                                        marginTop: 'auto',
                                        paddingTop: px(5, t),
                                    }}
                                >
                                    {t.price(p)}
                                </p>
                            </div>
                        </div>
                    );
                })}
            </div>
        );
    }

    const p = block.products[0];

    switch (block.style as Exclude<ItemStyle, 'grid'>) {
        case 'leaders':
            return (
                <div
                    style={{
                        padding: `${px(4, t)}px 0`,
                        paddingBottom: px(4, t) + bottom,
                    }}
                >
                    <div
                        style={{
                            display: 'flex',
                            alignItems: 'baseline',
                            gap: px(6, t),
                        }}
                    >
                        <p style={{ ...name, fontWeight: 600 }}>{p.name}</p>
                        <span
                            style={{
                                flex: 1,
                                minWidth: px(12, t),
                                borderBottom: `2px dotted ${c.line}`,
                                transform: 'translateY(-3px)',
                            }}
                        />
                        <p
                            style={{
                                ...price,
                                color: accent === '#151515' ? c.fg : accent,
                            }}
                        >
                            {t.price(p)}
                        </p>
                    </div>
                    <Desc text={p.description} t={t} />
                </div>
            );
        case 'elegant':
            return (
                <div
                    style={{
                        padding: `${px(8, t)}px 0`,
                        borderBottom:
                            t.heading === 'minimal'
                                ? 'none'
                                : `1px dotted ${c.line}`,
                        marginBottom: bottom,
                    }}
                >
                    <div
                        style={{
                            display: 'flex',
                            alignItems: 'baseline',
                            justifyContent: 'space-between',
                            gap: px(10, t),
                        }}
                    >
                        <p
                            style={{
                                ...name,
                                fontWeight: 500,
                                letterSpacing: '0.02em',
                            }}
                        >
                            {p.name}
                        </p>
                        <p
                            style={{
                                ...price,
                                fontWeight: 500,
                                fontStyle: 'italic',
                                color: accent === '#151515' ? c.fg : accent,
                            }}
                        >
                            {t.price(p)}
                        </p>
                    </div>
                    <Desc text={p.description} t={t} italic />
                </div>
            );
        case 'photo':
            return (
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: px(12, t),
                        padding: `${px(8, t)}px 0`,
                        borderBottom: `1px solid ${c.line}`,
                        marginBottom: bottom,
                    }}
                >
                    {t.showImages && <Thumb product={p} size={60} t={t} />}
                    <div style={{ flex: 1, minWidth: 0 }}>
                        <p style={name}>{p.name}</p>
                        <Desc text={p.description} t={t} clamp={3} />
                    </div>
                    <p
                        style={{
                            ...price,
                            color: accent === '#151515' ? c.fg : accent,
                        }}
                    >
                        {t.price(p)}
                    </p>
                </div>
            );
        default: // list
            return (
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'baseline',
                        justifyContent: 'space-between',
                        gap: px(12, t),
                        padding: `${px(7, t)}px 0`,
                        borderBottom: `1px solid ${c.line}`,
                        marginBottom: bottom,
                    }}
                >
                    <div style={{ flex: 1, minWidth: 0 }}>
                        <p style={name}>{p.name}</p>
                        <Desc text={p.description} t={t} />
                    </div>
                    <p
                        style={{
                            ...price,
                            color:
                                accent === '#151515' || t.heading !== 'bar'
                                    ? c.fg
                                    : accent,
                        }}
                    >
                        {t.price(p)}
                    </p>
                </div>
            );
    }
}

/** One block inside a flow-root wrapper so margins never leak between blocks. */
export function BlockView({
    block,
    gap,
    t,
}: {
    block: Block;
    gap: boolean;
    t: Theme;
}) {
    return (
        <div style={{ display: 'flow-root' }}>
            {block.kind === 'heading' ? (
                <SectionHeading title={block.title} gap={gap} t={t} />
            ) : (
                <ProductItems block={block} last={block.last} t={t} />
            )}
        </div>
    );
}

// ─── page ────────────────────────────────────────────────────────────────────

type PageProps = {
    page: PageLayout;
    index: number;
    total: number;
    blocks: Block[];
    geo: Geometry;
    t: Theme;
    header: ReactNode | null;
    footer: { note: string; showNumbers: boolean } | null;
};

export function MenuPage({
    page,
    index,
    total,
    blocks,
    geo,
    t,
    header,
    footer,
}: PageProps) {
    return (
        <div
            className="mp-page"
            style={{
                width: geo.pageW,
                height: geo.pageH,
                padding: geo.margin,
                boxSizing: 'border-box',
                background: t.palette.bg,
                color: t.palette.fg,
                fontFamily: t.bodyFont,
                display: 'flex',
                flexDirection: 'column',
                overflow: 'hidden',
            }}
        >
            {index === 0 && header}
            <div
                style={{
                    display: 'flex',
                    gap: COLUMN_GAP,
                    alignItems: 'flex-start',
                    flex: 1,
                    minHeight: 0,
                }}
            >
                {page.columns.map((col, ci) => (
                    <div key={ci} style={{ width: geo.columnW, flexShrink: 0 }}>
                        {col.map((bi, pos) => (
                            <BlockView
                                key={blocks[bi].id}
                                block={blocks[bi]}
                                gap={pos > 0}
                                t={t}
                            />
                        ))}
                    </div>
                ))}
            </div>
            {footer && (
                <MenuFooter
                    note={footer.note}
                    page={index + 1}
                    total={total}
                    showNumbers={footer.showNumbers}
                    t={t}
                />
            )}
        </div>
    );
}
