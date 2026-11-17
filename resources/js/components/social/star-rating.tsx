import { Star } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';
import type { RatingSummary } from '@/types';

const formats = new Map<string, Intl.NumberFormat>();

function formatAverage(value: number, language: string): string {
    const tag = language.replace('_', '-');
    let format = formats.get(tag);

    if (!format) {
        format = new Intl.NumberFormat(tag, {
            minimumFractionDigits: 1,
            maximumFractionDigits: 1,
        });
        formats.set(tag, format);
    }

    return format.format(value);
}

/** Five stars filled to the average (fractions included), the average and the review count. */
export function StarRating({
    rating,
    className,
}: {
    rating: RatingSummary;
    className?: string;
}) {
    const { t, i18n } = useTranslation();
    const label = t('social.rating_label', {
        average: formatAverage(rating.average, i18n.language),
        count: rating.count,
    });

    return (
        <div
            className={cn('flex items-center gap-2 text-sm', className)}
            role="img"
            aria-label={label}
            title={label}
        >
            <span className="font-semibold tabular-nums">
                {formatAverage(rating.average, i18n.language)}
            </span>
            <span className="flex" aria-hidden>
                {[0, 1, 2, 3, 4].map((i) => {
                    const fill = Math.max(0, Math.min(1, rating.average - i));

                    return (
                        <span key={i} className="relative size-4">
                            <Star className="absolute inset-0 size-4 text-muted-foreground/40" />
                            <span
                                className="absolute inset-0 overflow-hidden"
                                style={{ width: `${fill * 100}%` }}
                            >
                                <Star className="size-4 fill-amber-400 text-amber-400" />
                            </span>
                        </span>
                    );
                })}
            </span>
            <span className="text-muted-foreground">
                {t('social.reviews_count', { count: rating.count })}
            </span>
        </div>
    );
}
