import { Link, usePage } from '@inertiajs/react';
import { BookOpen, FolderGit2, LayoutGrid, ScrollText, ShoppingBag, Tag, UtensilsCrossed } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import CategoryController from '@/actions/App/Http/Controllers/Admin/CategoryController';
import MenuController from '@/actions/App/Http/Controllers/Restaurant/MenuController';
import ProductController from '@/actions/App/Http/Controllers/Restaurant/ProductController';
import TableController from '@/actions/App/Http/Controllers/Restaurant/TableController';
import { dashboard } from '@/routes';
import type { Auth, NavItem } from '@/types';

export function AppSidebar() {
    const { t } = useTranslation();
    const { auth } = usePage<{ auth: Auth }>().props;
    const isAdmin = auth.user.account_type === 'admin';
    const isRestaurant = auth.user.account_type === 'restaurant';

    const mainNavItems: NavItem[] = isAdmin
        ? [
              {
                  title: t('nav.categories'),
                  href: CategoryController.index.url(),
                  icon: Tag,
              },
          ]
        : isRestaurant
          ? [
                {
                    title: t('nav.tables'),
                    href: TableController.index.url(),
                    icon: UtensilsCrossed,
                },
                {
                    title: t('nav.menus'),
                    href: MenuController.index.url(),
                    icon: ScrollText,
                },
                {
                    title: t('nav.products'),
                    href: ProductController.index.url(),
                    icon: ShoppingBag,
                },
            ]
          : [
                {
                    title: t('nav.dashboard'),
                    href: dashboard(),
                    icon: LayoutGrid,
                },
            ];

    const footerNavItems: NavItem[] = [
        {
            title: t('nav.repository'),
            href: 'https://github.com/laravel/react-starter-kit',
            icon: FolderGit2,
        },
        {
            title: t('nav.documentation'),
            href: 'https://laravel.com/docs/starter-kits#react',
            icon: BookOpen,
        },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={isAdmin ? CategoryController.index.url() : isRestaurant ? ProductController.index.url() : dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
