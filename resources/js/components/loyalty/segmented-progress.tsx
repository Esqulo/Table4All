import { Gift } from 'lucide-react';
import type { CSSProperties } from 'react';
import { cn } from '@/lib/utils';

/** Past this many segments the bar is drawn as one continuous fill. */
const MAX_DISCRETE_SEGMENTS = 20;

/**
 * The loyalty bar: one segment per visit, with a gift at every tier. The last
 * tier sits at the end of the bar; earlier ones are marked along it. Filled
 * segments and reached gifts carry the brand color.
 */
export default function SegmentedProgress({
    segments,
    filled,
    checkpoints = [segments],
    label,
    disabled = false,
}: {
    segments: number;
    filled: number;
    /** Visit counts that earn a reward (the last one should equal `segments`). */
    checkpoints?: number[];
    label: string;
    disabled?: boolean;
}) {
    const discrete = segments <= MAX_DISCRETE_SEGMENTS;
    const along = checkpoints.filter((c) => c < segments);
    const reachedEnd = filled >= segments;

    return (
        <div className="flex items-center gap-2">
            <div className="relative flex h-7 flex-1 items-center">
                <div
                    role="progressbar"
                    aria-label={label}
                    aria-valuemin={0}
                    aria-valuemax={segments}
                    aria-valuenow={filled}
                    className={cn(
                        'flex h-3 w-full overflow-hidden rounded-full',
                        discrete ? 'gap-[3px]' : 'bg-muted',
                        disabled && 'opacity-50',
                    )}
                >
                    {discrete ? (
                        Array.from({ length: segments }, (_, i) => (
                            <span
                                key={i}
                                className={cn(
                                    'h-full flex-1 rounded-full transition-colors duration-500',
                                    i < filled
                                        ? 'bg-primary shadow-[0_0_12px_-2px_var(--primary)]'
                                        : 'bg-muted',
                                )}
                            />
                        ))
                    ) : (
                        <span
                            className="h-full rounded-full bg-primary shadow-[0_0_12px_-2px_var(--primary)] transition-[width] duration-500"
                            style={{
                                width: `${Math.min(100, (filled / segments) * 100)}%`,
                            }}
                        />
                    )}
                </div>
                {along.map((checkpoint) => (
                    <GiftMarker
                        key={checkpoint}
                        reached={filled >= checkpoint}
                        disabled={disabled}
                        className="absolute size-6 -translate-x-1/2"
                        style={{ left: `${(checkpoint / segments) * 100}%` }}
                    />
                ))}
            </div>
            <GiftMarker
                reached={reachedEnd}
                disabled={disabled}
                className={cn(
                    'size-8',
                    reachedEnd && 'motion-safe:animate-bounce',
                )}
            />
        </div>
    );
}

function GiftMarker({
    reached,
    disabled,
    className,
    style,
}: {
    reached: boolean;
    disabled: boolean;
    className?: string;
    style?: CSSProperties;
}) {
    return (
        <span
            className={cn(
                'flex shrink-0 items-center justify-center rounded-full border-2 transition-colors',
                reached
                    ? 'border-primary bg-primary text-primary-foreground'
                    : 'border-dashed border-muted-foreground/40 bg-card text-muted-foreground',
                disabled && 'opacity-50',
                className,
            )}
            style={style}
            aria-hidden
        >
            <Gift className="size-3.5" />
        </span>
    );
}
