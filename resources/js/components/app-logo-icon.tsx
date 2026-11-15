import type { ImgHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

type Props = Omit<ImgHTMLAttributes<HTMLImageElement>, 'src'> & {
    /** `green` for light or neutral surfaces, `white` for brand-green or dark panels. */
    tone?: 'green' | 'white';
};

/** Table4All symbol (the isometric chair). Decorative by default. */
export default function AppLogoIcon({
    tone = 'green',
    className,
    alt = '',
    ...props
}: Props) {
    return (
        <img
            src={`/images/brand/mark-${tone}.svg`}
            alt={alt}
            draggable={false}
            className={cn('object-contain', className)}
            {...props}
        />
    );
}
