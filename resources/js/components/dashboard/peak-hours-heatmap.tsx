import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { formatBRL } from '@/lib/money';
import type { HeatmapCell } from '@/types';
import { intlLocale, weekdayName } from './format';

const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];
// Sequential: one hue (the brand primary), light to dark, in five steps.
const STEPS = [0.14, 0.3, 0.5, 0.72, 1];

/**
 * When customers order: items per weekday and hour. Only the hours that saw
 * any order are drawn (a bar open 18h–02h doesn't need a 24-column grid).
 */
export default function PeakHoursHeatmap({ cells }: { cells: HeatmapCell[] }) {
    const { t, i18n } = useTranslation();
    const language = i18n.language;
    const [hovered, setHovered] = useState<HeatmapCell | null>(null);

    if (cells.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('dashboards.no_data')}
            </p>
        );
    }

    const byKey = new Map(cells.map((c) => [`${c.weekday}:${c.hour}`, c]));
    const hours = [...new Set(cells.map((c) => c.hour))].sort((a, b) => a - b);
    const span: number[] = [];

    for (let h = hours[0]; h <= hours[hours.length - 1]; h++) {
        span.push(h);
    }

    const max = Math.max(...cells.map((c) => c.items), 1);

    const caption = hovered
        ? t('dashboards.heatmap.cell', {
              day: weekdayName(hovered.weekday, language),
              hour: hovered.hour,
              count: hovered.items,
              value: formatBRL(hovered.revenue, intlLocale(language)),
          })
        : t('dashboards.heatmap.hint');

    return (
        <div>
            <p
                className="mb-3 min-h-4 text-xs text-muted-foreground"
                aria-live="polite"
            >
                {caption}
            </p>
            <div className="overflow-x-auto pb-1">
                <div
                    className="grid min-w-max gap-[3px]"
                    style={{
                        gridTemplateColumns: `2.5rem repeat(${span.length}, minmax(1.25rem, 2.5rem))`,
                    }}
                    onMouseLeave={() => setHovered(null)}
                >
                    {WEEKDAYS.map((weekday) => (
                        <Row
                            key={weekday}
                            label={weekdayName(weekday, language)}
                            cells={span.map((hour) => {
                                const cell = byKey.get(`${weekday}:${hour}`);

                                return {
                                    key: hour,
                                    cell: cell ?? {
                                        weekday,
                                        hour,
                                        items: 0,
                                        revenue: 0,
                                    },
                                };
                            })}
                            max={max}
                            onHover={setHovered}
                        />
                    ))}
                    <span />
                    {span.map((hour) => (
                        <span
                            key={hour}
                            className="pt-1 text-center text-[10px] text-muted-foreground tabular-nums"
                        >
                            {hour % 2 === 0 ? `${hour}h` : ''}
                        </span>
                    ))}
                </div>
            </div>
            <div className="mt-3 flex items-center justify-end gap-1.5 text-[10px] text-muted-foreground">
                <span>{t('dashboards.heatmap.less')}</span>
                {STEPS.map((opacity) => (
                    <span
                        key={opacity}
                        className="size-3 rounded-[3px] bg-primary"
                        style={{ opacity }}
                    />
                ))}
                <span>{t('dashboards.heatmap.more')}</span>
            </div>
        </div>
    );
}

function Row({
    label,
    cells,
    max,
    onHover,
}: {
    label: string;
    cells: { key: number; cell: HeatmapCell }[];
    max: number;
    onHover: (cell: HeatmapCell) => void;
}) {
    return (
        <>
            <span className="flex items-center text-[11px] text-muted-foreground capitalize">
                {label}
            </span>
            {cells.map(({ key, cell }) => {
                const step =
                    cell.items === 0
                        ? null
                        : STEPS[
                              Math.min(
                                  STEPS.length - 1,
                                  Math.floor((cell.items / max) * STEPS.length),
                              )
                          ];

                return (
                    <span
                        key={key}
                        onMouseEnter={() => onHover(cell)}
                        // Touch screens have no hover: a tap shows the cell.
                        onClick={() => onHover(cell)}
                        className="h-6 rounded-[4px] bg-muted transition-shadow hover:ring-2 hover:ring-foreground/40 sm:h-7"
                    >
                        {step !== null && (
                            <span
                                className="block size-full rounded-[4px] bg-primary"
                                style={{ opacity: step }}
                            />
                        )}
                    </span>
                );
            })}
        </>
    );
}
