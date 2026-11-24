import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Compass, Menu, Newspaper, Trophy, Utensils } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import CustomerJoinController from '@/actions/App/Http/Controllers/Customer/JoinController';
import CustomerRewardController from '@/actions/App/Http/Controllers/Customer/RewardController';
import DiscoverController from '@/actions/App/Http/Controllers/DiscoverController';
import SocialFeedController from '@/actions/App/Http/Controllers/SocialFeedController';
import { useSidebar } from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';

/**
 * The customer's main destinations at thumb reach on phones, with the table
 * (what they come to the app for at the venue) raised in the middle. It holds
 * only the essentials: "Menu" opens the sidebar, which lists every screen.
 * The layout reserves its height through --bottom-nav-h.
 */
export function CustomerBottomNav() {
    const { t } = useTranslation();
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
    const { setOpenMobile } = useSidebar();
    const tableUrl = CustomerJoinController.index.url();

    return (
        <nav
            aria-label={t('nav.navigation_menu')}
            className="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-background/95 pb-[env(safe-area-inset-bottom)] shadow-[0_-6px_18px_-12px_oklch(0.2_0.03_155/0.35)] backdrop-blur md:hidden print:hidden"
        >
            <ul className="mx-auto grid h-16 max-w-md grid-cols-5 items-end">
                <BarLink
                    href={SocialFeedController.index.url()}
                    icon={Newspaper}
                    label={t('nav.feed')}
                    active={isCurrentUrl(SocialFeedController.index.url())}
                />
                <BarLink
                    href={DiscoverController.index.url()}
                    icon={Compass}
                    label={t('nav.discover')}
                    active={isCurrentUrl(DiscoverController.index.url())}
                />
                <li className="flex justify-center">
                    <Link
                        href={tableUrl}
                        prefetch
                        aria-current={
                            isCurrentOrParentUrl(tableUrl) ? 'page' : undefined
                        }
                        className="group flex -translate-y-3 flex-col items-center gap-1 pb-1.5 text-[11px] font-semibold"
                    >
                        <span
                            className={cn(
                                'flex size-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-[0_8px_18px_-6px_oklch(0.45_0.12_157/0.7)] ring-4 ring-background transition-transform group-active:scale-95',
                                isCurrentOrParentUrl(tableUrl) &&
                                    'ring-primary/25',
                            )}
                        >
                            <Utensils className="size-6" />
                        </span>
                        <span
                            className={
                                isCurrentOrParentUrl(tableUrl)
                                    ? 'text-primary'
                                    : 'text-foreground'
                            }
                        >
                            {t('nav.table')}
                        </span>
                    </Link>
                </li>
                <BarLink
                    href={CustomerRewardController.index.url()}
                    icon={Trophy}
                    label={t('nav.rewards')}
                    active={isCurrentUrl(CustomerRewardController.index.url())}
                />
                <li className="flex h-full">
                    <button
                        type="button"
                        onClick={() => setOpenMobile(true)}
                        className={itemClass(false)}
                        aria-label={t('nav.open_menu')}
                    >
                        <Menu className="size-5" />
                        {t('nav.menu')}
                    </button>
                </li>
            </ul>
        </nav>
    );
}

function itemClass(active: boolean): string {
    return cn(
        'flex h-full w-full flex-col items-center justify-center gap-1 text-[11px] font-medium transition-colors active:scale-95',
        active ? 'text-primary' : 'text-muted-foreground hover:text-foreground',
    );
}

function BarLink({
    href,
    icon: Icon,
    label,
    active,
}: {
    href: string;
    icon: LucideIcon;
    label: string;
    active: boolean;
}) {
    return (
        <li className="flex h-full">
            <Link
                href={href}
                prefetch
                aria-current={active ? 'page' : undefined}
                className={itemClass(active)}
            >
                <Icon className={cn('size-5', active && 'stroke-[2.5]')} />
                {label}
            </Link>
        </li>
    );
}
