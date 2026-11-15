import { cn } from '@/lib/utils';

type Props = {
    className?: string;
    /**
     * Brand rule: symbol and wordmark always share one color.
     * `green` on any light or dark surface, `white` only on brand-green surfaces.
     */
    tone?: 'green' | 'white';
};

/** "Table 4 all" wordmark. Size it with a height class, e.g. `h-5`. */
export default function AppLogoWordmark({ className, tone = 'green' }: Props) {
    return (
        <img
            src={`/images/brand/wordmark-${tone}.png`}
            alt="Table4All"
            draggable={false}
            className={cn('w-auto', className)}
        />
    );
}
