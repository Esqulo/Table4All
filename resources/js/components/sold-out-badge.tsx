import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';

/** "Esgotado": the product can't be ordered until the restaurant marks it back. */
export function SoldOutBadge({ className }: { className?: string }) {
    const { t } = useTranslation();

    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center rounded-full bg-destructive/10 px-2 py-0.5 text-[0.7rem] font-semibold tracking-wide text-destructive uppercase',
                className,
            )}
        >
            {t('products.sold_out')}
        </span>
    );
}
