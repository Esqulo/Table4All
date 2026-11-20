import { MessageSquareText } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * The note on an ordered item ("sem cebola", "ao ponto"), in a colour the
 * kitchen and the waiters can't miss.
 */
export function ItemNote({
    notes,
    className,
}: {
    notes: string | null | undefined;
    className?: string;
}) {
    if (!notes) {
        return null;
    }

    return (
        <p
            className={cn(
                'flex items-start gap-1.5 rounded-md bg-amber-500/10 px-2 py-1 text-sm font-medium break-words text-amber-700 dark:text-amber-300',
                className,
            )}
        >
            <MessageSquareText className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <span className="min-w-0">{notes}</span>
        </p>
    );
}
