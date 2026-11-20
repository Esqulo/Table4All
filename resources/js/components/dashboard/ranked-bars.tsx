import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type RankedRow = {
    key: string | number;
    label: string;
    /** Drives the bar length. */
    value: number;
    /** Text shown at the end of the row. */
    display: string;
    detail?: ReactNode;
};

/**
 * A ranked list with a thin magnitude bar under each name: easier to read
 * on a phone than a bar chart with rotated labels, and the numbers stay
 * in text.
 */
export default function RankedBars({ rows }: { rows: RankedRow[] }) {
    const max = Math.max(...rows.map((r) => r.value), 1);

    return (
        <ol className="space-y-3">
            {rows.map((row, index) => (
                <li key={row.key} className="group">
                    <div className="mb-1 flex items-baseline gap-2 text-sm">
                        <span className="w-4 text-xs font-medium text-muted-foreground tabular-nums">
                            {index + 1}
                        </span>
                        <span className="min-w-0 flex-1 truncate">
                            {row.label}
                        </span>
                        {row.detail && (
                            <span className="text-xs text-muted-foreground">
                                {row.detail}
                            </span>
                        )}
                        <span className="font-semibold tabular-nums">
                            {row.display}
                        </span>
                    </div>
                    <div className="ml-6 h-1.5 overflow-hidden rounded-full bg-muted">
                        <div
                            className={cn(
                                'h-full rounded-full transition-[width] duration-500',
                                // Emphasis on the leader, the rest recede.
                                index === 0 ? 'bg-primary' : 'bg-primary/55',
                            )}
                            style={{
                                width: `${Math.max(2, (row.value / max) * 100)}%`,
                            }}
                        />
                    </div>
                </li>
            ))}
        </ol>
    );
}
