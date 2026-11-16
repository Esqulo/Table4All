import {
    DndContext,
    KeyboardSensor,
    PointerSensor,
    closestCenter,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { DragEndEvent } from '@dnd-kit/core';
import {
    restrictToParentElement,
    restrictToVerticalAxis,
} from '@dnd-kit/modifiers';
import {
    SortableContext,
    arrayMove,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
    ArrowDownAZ,
    ArrowDownWideNarrow,
    ChevronDown,
    ChevronRight,
    Eye,
    EyeOff,
    GripVertical,
} from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Input } from '@/components/ui/input';
import { formatBRL } from '@/lib/money';
import type { Category, Product } from '@/types';
import { ITEM_STYLES } from './config';
import type { ItemStyle, MenuLayout, SectionLayout } from './config';
import { UNCATEGORISED } from './layout';

type Props = {
    layout: MenuLayout;
    categories: Category[];
    products: Product[];
    onChange: (patch: Partial<MenuLayout>) => void;
};

function useDndSensors() {
    return useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );
}

function reorder<T extends number>(ids: T[], event: DragEndEvent): T[] | null {
    const { active, over } = event;

    if (!over || active.id === over.id) {
        return null;
    }

    const from = ids.indexOf(active.id as T);
    const to = ids.indexOf(over.id as T);

    return from === -1 || to === -1 ? null : arrayMove(ids, from, to);
}

function sortIds(
    ids: number[],
    byId: Map<number, Product>,
    by: 'name' | 'price',
): number[] {
    return [...ids].sort((a, b) => {
        const pa = byId.get(a)!;
        const pb = byId.get(b)!;

        return by === 'price'
            ? pa.price - pb.price || pa.name.localeCompare(pb.name)
            : pa.name.localeCompare(pb.name);
    });
}

function SortButtons({ onSort }: { onSort: (by: 'name' | 'price') => void }) {
    const { t } = useTranslation();
    const cls =
        'inline-flex items-center gap-1 rounded border border-border px-1.5 py-0.5 text-[11px] text-muted-foreground hover:border-primary/40 hover:text-foreground';

    return (
        <div className="flex gap-1">
            <button
                type="button"
                className={cls}
                onClick={() => onSort('name')}
            >
                <ArrowDownAZ className="h-3 w-3" /> {t('menus.print_sort_az')}
            </button>
            <button
                type="button"
                className={cls}
                onClick={() => onSort('price')}
            >
                <ArrowDownWideNarrow className="h-3 w-3" />{' '}
                {t('menus.print_sort_price')}
            </button>
        </div>
    );
}

function VisibilityButton({
    hidden,
    onToggle,
}: {
    hidden: boolean;
    onToggle: () => void;
}) {
    const { t } = useTranslation();

    return (
        <button
            type="button"
            onClick={onToggle}
            title={hidden ? t('menus.print_show') : t('menus.print_hide')}
            aria-label={hidden ? t('menus.print_show') : t('menus.print_hide')}
            className="shrink-0 rounded p-0.5 text-muted-foreground hover:text-foreground"
        >
            {hidden ? (
                <EyeOff className="h-3.5 w-3.5" />
            ) : (
                <Eye className="h-3.5 w-3.5" />
            )}
        </button>
    );
}

// ─── products ────────────────────────────────────────────────────────────────

function ProductRow({
    product,
    hidden,
    onToggle,
}: {
    product: Product;
    hidden: boolean;
    onToggle: () => void;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: product.id });

    return (
        <div
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition }}
            className={`flex items-center gap-2 rounded px-1.5 py-1 text-xs ${isDragging ? 'relative z-10 bg-muted opacity-70' : 'hover:bg-muted/50'}`}
        >
            <span
                {...attributes}
                {...listeners}
                className="cursor-grab touch-none text-muted-foreground"
            >
                <GripVertical className="h-3.5 w-3.5" />
            </span>
            <span
                className={`flex-1 truncate ${hidden ? 'text-muted-foreground line-through' : ''}`}
            >
                {product.name}
            </span>
            <span className="shrink-0 text-muted-foreground">
                {formatBRL(product.price)}
            </span>
            <VisibilityButton hidden={hidden} onToggle={onToggle} />
        </div>
    );
}

function ProductList({
    ids,
    byId,
    hidden,
    onReorder,
    onToggle,
}: {
    ids: number[];
    byId: Map<number, Product>;
    hidden: Set<number>;
    onReorder: (ids: number[]) => void;
    onToggle: (id: number) => void;
}) {
    const sensors = useDndSensors();

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            modifiers={[restrictToVerticalAxis, restrictToParentElement]}
            onDragEnd={(e) => {
                const next = reorder(ids, e);

                if (next) {
                    onReorder(next);
                }
            }}
        >
            <SortableContext items={ids} strategy={verticalListSortingStrategy}>
                {ids.map((id) => {
                    const p = byId.get(id);

                    return p ? (
                        <ProductRow
                            key={id}
                            product={p}
                            hidden={hidden.has(id)}
                            onToggle={() => onToggle(id)}
                        />
                    ) : null;
                })}
            </SortableContext>
        </DndContext>
    );
}

// ─── sections ────────────────────────────────────────────────────────────────

function SectionRow({
    section,
    name,
    defaultStyle,
    byId,
    hidden,
    onChange,
    onToggleProduct,
}: {
    section: SectionLayout;
    name: string;
    defaultStyle: ItemStyle;
    byId: Map<number, Product>;
    hidden: Set<number>;
    onChange: (patch: Partial<SectionLayout>) => void;
    onToggleProduct: (id: number) => void;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: section.key });
    const visibleCount = section.products.filter(
        (id) => !hidden.has(id),
    ).length;

    return (
        <div
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition }}
            className={`rounded-md ${isDragging ? 'relative z-10 bg-muted opacity-70' : ''}`}
        >
            <div className="flex items-center gap-1.5 rounded-md px-1.5 py-1.5 hover:bg-muted/50">
                <span
                    {...attributes}
                    {...listeners}
                    className="shrink-0 cursor-grab touch-none text-muted-foreground"
                >
                    <GripVertical className="h-4 w-4" />
                </span>
                <button
                    type="button"
                    onClick={() => setOpen((o) => !o)}
                    className="flex min-w-0 flex-1 items-center gap-1.5 text-left"
                >
                    {open ? (
                        <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    ) : (
                        <ChevronRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    )}
                    <span
                        className={`flex-1 truncate text-sm font-medium ${section.hidden ? 'text-muted-foreground line-through' : ''}`}
                    >
                        {section.title?.trim() || name}
                    </span>
                    {section.style && (
                        <span className="shrink-0 rounded bg-primary/10 px-1 text-[10px] text-primary">
                            {t(`menus.print_style_${section.style}`)}
                        </span>
                    )}
                    <span className="shrink-0 text-xs text-muted-foreground">
                        {visibleCount}/{section.products.length}
                    </span>
                </button>
                <VisibilityButton
                    hidden={section.hidden}
                    onToggle={() => onChange({ hidden: !section.hidden })}
                />
            </div>

            {open && (
                <div className="mb-2 ml-5 space-y-2 border-l border-border pt-1 pl-2">
                    <div className="grid gap-1">
                        <label className="text-[11px] text-muted-foreground">
                            {t('menus.print_section_title')}
                        </label>
                        <Input
                            value={section.title ?? ''}
                            placeholder={name}
                            maxLength={120}
                            className="h-8 text-xs"
                            onChange={(e) =>
                                onChange({ title: e.target.value || null })
                            }
                        />
                    </div>
                    <div className="grid gap-1">
                        <label className="text-[11px] text-muted-foreground">
                            {t('menus.print_section_style')}
                        </label>
                        <select
                            value={section.style ?? ''}
                            onChange={(e) =>
                                onChange({
                                    style: (e.target.value ||
                                        null) as ItemStyle | null,
                                })
                            }
                            className="h-8 rounded-md border border-input bg-background px-2 text-xs"
                        >
                            <option value="">
                                {t('menus.print_style_default', {
                                    style: t(
                                        `menus.print_style_${defaultStyle}`,
                                    ),
                                })}
                            </option>
                            {ITEM_STYLES.map((s) => (
                                <option key={s} value={s}>
                                    {t(`menus.print_style_${s}`)}
                                </option>
                            ))}
                        </select>
                    </div>
                    <SortButtons
                        onSort={(by) =>
                            onChange({
                                products: sortIds(section.products, byId, by),
                            })
                        }
                    />
                    <div>
                        <ProductList
                            ids={section.products}
                            byId={byId}
                            hidden={hidden}
                            onReorder={(products) => onChange({ products })}
                            onToggle={onToggleProduct}
                        />
                    </div>
                </div>
            )}
        </div>
    );
}

// ─── editor ──────────────────────────────────────────────────────────────────

export function StructureEditor({
    layout,
    categories,
    products,
    onChange,
}: Props) {
    const { t } = useTranslation();
    const sensors = useDndSensors();
    const byId = new Map(products.map((p) => [p.id, p]));
    const names = new Map(categories.map((c) => [c.id, c.name]));
    const hidden = new Set(layout.hidden_products);

    const toggleProduct = (id: number) =>
        onChange({
            hidden_products: hidden.has(id)
                ? layout.hidden_products.filter((h) => h !== id)
                : [...layout.hidden_products, id],
        });

    if (products.length === 0) {
        return (
            <p className="py-2 text-xs text-muted-foreground italic">
                {t('menus.print_no_products')}
            </p>
        );
    }

    if (!layout.grouped) {
        return (
            <div className="space-y-2">
                <p className="text-xs text-muted-foreground">
                    {t('menus.print_order_flat_hint')}
                </p>
                <SortButtons
                    onSort={(by) =>
                        onChange({
                            flat_order: sortIds(layout.flat_order, byId, by),
                        })
                    }
                />
                <div>
                    <ProductList
                        ids={layout.flat_order}
                        byId={byId}
                        hidden={hidden}
                        onReorder={(flat_order) => onChange({ flat_order })}
                        onToggle={toggleProduct}
                    />
                </div>
            </div>
        );
    }

    const keys = layout.sections.map((s) => s.key);
    const updateSection = (key: number, patch: Partial<SectionLayout>) =>
        onChange({
            sections: layout.sections.map((s) =>
                s.key === key ? { ...s, ...patch } : s,
            ),
        });

    return (
        <div className="space-y-2">
            <p className="text-xs text-muted-foreground">
                {t('menus.print_order_hint')}
            </p>
            <div>
                <DndContext
                    sensors={sensors}
                    collisionDetection={closestCenter}
                    modifiers={[
                        restrictToVerticalAxis,
                        restrictToParentElement,
                    ]}
                    onDragEnd={(e) => {
                        const next = reorder(keys, e);

                        if (next) {
                            onChange({
                                sections: next.map(
                                    (k) =>
                                        layout.sections.find(
                                            (s) => s.key === k,
                                        )!,
                                ),
                            });
                        }
                    }}
                >
                    <SortableContext
                        items={keys}
                        strategy={verticalListSortingStrategy}
                    >
                        {layout.sections.map((section) => (
                            <SectionRow
                                key={section.key}
                                section={section}
                                name={
                                    section.key === UNCATEGORISED
                                        ? t('menus.print_uncategorised')
                                        : (names.get(section.key) ?? '')
                                }
                                defaultStyle={layout.item_style}
                                byId={byId}
                                hidden={hidden}
                                onChange={(patch) =>
                                    updateSection(section.key, patch)
                                }
                                onToggleProduct={toggleProduct}
                            />
                        ))}
                    </SortableContext>
                </DndContext>
            </div>
        </div>
    );
}
