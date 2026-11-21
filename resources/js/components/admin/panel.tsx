import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** A titled card, the same frame the restaurant dashboard uses. */
export default function Panel({
    title,
    action,
    children,
    className,
}: {
    title: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section
            className={cn(
                'min-w-0 rounded-2xl border bg-card p-4 shadow-xs',
                className,
            )}
        >
            <div className="mb-4 flex min-h-10 items-center justify-between gap-3 border-b border-dashed pb-3">
                <h3 className="font-receipt text-[11px] font-medium tracking-[0.14em] text-muted-foreground uppercase">
                    {title}
                </h3>
                {action}
            </div>
            {children}
        </section>
    );
}

/** A section heading inside a long page. */
export function SectionTitle({
    title,
    description,
}: {
    title: string;
    description?: string;
}) {
    return (
        <div className="pt-2">
            <h2 className="font-display text-lg font-bold">{title}</h2>
            {description && (
                <p className="text-sm text-muted-foreground">{description}</p>
            )}
        </div>
    );
}
