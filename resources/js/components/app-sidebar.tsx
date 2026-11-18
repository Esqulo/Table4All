import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Inbox,
    ConciergeBell,
    LayoutGrid,
    ListOrdered,
    Newspaper,
    Percent,
    QrCode,
    ScrollText,
    ShoppingBag,
    Store,
    Tag,
    Users,
    UtensilsCrossed,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import AdminAdmissionRequestController from '@/actions/App/Http/Controllers/Admin/AdmissionRequestController';
import CategoryController from '@/actions/App/Http/Controllers/Admin/CategoryController';
import CustomerJoinController from '@/actions/App/Http/Controllers/Customer/JoinController';
import DashboardController from '@/actions/App/Http/Controllers/Restaurant/DashboardController';
import DeliveryController from '@/actions/App/Http/Controllers/Restaurant/DeliveryController';
import EstablishmentController from '@/actions/App/Http/Controllers/Restaurant/EstablishmentController';
import MenuController from '@/actions/App/Http/Controllers/Restaurant/MenuController';
import ProductController from '@/actions/App/Http/Controllers/Restaurant/ProductController';
import QueueController from '@/actions/App/Http/Controllers/Restaurant/QueueController';
import SaleController from '@/actions/App/Http/Controllers/Restaurant/SaleController';
import TableController from '@/actions/App/Http/Controllers/Restaurant/TableController';
import WaiterController from '@/actions/App/Http/Controllers/Restaurant/WaiterController';
import SocialFeedController from '@/actions/App/Http/Controllers/SocialFeedController';
import AppLogo from '@/components/app-logo';
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
import { dashboard } from '@/routes';
import type { Auth, NavItem } from '@/types';

export function AppSidebar() {
    const { t } = useTranslation();
    const { auth } = usePage<{ auth: Auth }>().props;
    const isAdmin = auth.user.account_type === 'admin';
    const isRestaurant = auth.user.account_type === 'restaurant';
    const isWaiter = auth.user.account_type === 'waiter';
    const isCustomer = auth.user.account_type === 'customer';

    const mainNavItems: NavItem[] = isAdmin
        ? [
              {
                  title: t('nav.categories'),
                  href: CategoryController.index.url(),
                  icon: Tag,
              },
              {
                  title: t('nav.admission_requests'),
                  href: AdminAdmissionRequestController.index.url(),
                  icon: Inbox,
              },
          ]
        : isRestaurant
          ? [
                {
                    title: t('nav.dashboards'),
                    href: DashboardController.index.url(),
                    icon: BarChart3,
                },
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
                {
                    title: t('nav.queues'),
                    href: QueueController.index.url(),
                    icon: ListOrdered,
                },
                {
                    title: t('nav.waiters'),
                    href: WaiterController.index.url(),
                    icon: Users,
                },
                {
                    title: t('nav.sales'),
                    href: SaleController.index.url(),
                    icon: Percent,
                },
                {
                    title: t('nav.delivery'),
                    href: DeliveryController.index.url(),
                    icon: ConciergeBell,
                },
                {
                    title: t('nav.establishment'),
                    href: EstablishmentController.edit.url(),
                    icon: Store,
                },
                {
                    title: t('nav.feed'),
                    href: SocialFeedController.index.url(),
                    icon: Newspaper,
                },
            ]
          : isWaiter
            ? [
                  {
                      title: t('nav.tables'),
                      href: TableController.index.url(),
                      icon: UtensilsCrossed,
                  },
                  {
                      title: t('nav.products'),
                      href: ProductController.index.url(),
                      icon: ShoppingBag,
                  },
                  {
                      title: t('nav.delivery'),
                      href: DeliveryController.index.url(),
                      icon: ConciergeBell,
                  },
              ]
            : isCustomer
              ? [
                    {
                        title: t('nav.feed'),
                        href: SocialFeedController.index.url(),
                        icon: Newspaper,
                    },
                    {
                        title: t('nav.join_table'),
                        href: CustomerJoinController.index.url(),
                        icon: QrCode,
                    },
                ]
              : [
                    {
                        title: t('nav.dashboard'),
                        href: dashboard(),
                        icon: LayoutGrid,
                    },
                ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            {/* Same height as AppSidebarHeader (h-16, h-12 collapsed) so both dashed lines line up. */}
            <SidebarHeader className="h-16 shrink-0 justify-center border-b border-dashed border-sidebar-border py-0 group-data-[collapsible=icon]:h-12">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link
                                href={
                                    isAdmin
                                        ? CategoryController.index.url()
                                        : isRestaurant
                                          ? ProductController.index.url()
                                          : isWaiter
                                            ? TableController.index.url()
                                            : isCustomer
                                              ? SocialFeedController.index.url()
                                              : dashboard()
                                }
                                prefetch
                            >
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="pt-2">
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter className="border-t border-dashed border-sidebar-border pt-3">
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
