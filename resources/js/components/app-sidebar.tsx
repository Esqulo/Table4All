import { Link, usePage } from '@inertiajs/react';
import {
    BadgePercent,
    BarChart3,
    BellRing,
    HandCoins,
    Landmark,
    Inbox,
    ConciergeBell,
    LayoutGrid,
    ListOrdered,
    Newspaper,
    Compass,
    Percent,
    ScrollText,
    ShoppingBag,
    Store,
    Tag,
    Users,
    UtensilsCrossed,
    Utensils,
    Gift,
    Trophy,
    Gauge,
    Layers,
    ShieldCheck,
    UserCog,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import AdminUserController from '@/actions/App/Http/Controllers/Admin/AdminUserController';
import AdminAdmissionRequestController from '@/actions/App/Http/Controllers/Admin/AdmissionRequestController';
import CategoryController from '@/actions/App/Http/Controllers/Admin/CategoryController';
import AdminCouponController from '@/actions/App/Http/Controllers/Admin/CouponController';
import FeeTierController from '@/actions/App/Http/Controllers/Admin/FeeTierController';
import OverviewController from '@/actions/App/Http/Controllers/Admin/OverviewController';
import AdminPayoutController from '@/actions/App/Http/Controllers/Admin/PayoutController';
import AdminRestaurantController from '@/actions/App/Http/Controllers/Admin/RestaurantController';
import AdminAccountController from '@/actions/App/Http/Controllers/Admin/UserController';
import CustomerJoinController from '@/actions/App/Http/Controllers/Customer/JoinController';
import CustomerRewardController from '@/actions/App/Http/Controllers/Customer/RewardController';
import DiscoverController from '@/actions/App/Http/Controllers/DiscoverController';
import CallController from '@/actions/App/Http/Controllers/Restaurant/CallController';
import CouponController from '@/actions/App/Http/Controllers/Restaurant/CouponController';
import DashboardController from '@/actions/App/Http/Controllers/Restaurant/DashboardController';
import DeliveryController from '@/actions/App/Http/Controllers/Restaurant/DeliveryController';
import EstablishmentController from '@/actions/App/Http/Controllers/Restaurant/EstablishmentController';
import FinanceController from '@/actions/App/Http/Controllers/Restaurant/FinanceController';
import IncentiveController from '@/actions/App/Http/Controllers/Restaurant/IncentiveController';
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
import type { StaffFeature } from '@/hooks/use-permission';
import { dashboard } from '@/routes';
import type { Auth, NavItem } from '@/types';

export function AppSidebar() {
    const { t } = useTranslation();
    const { auth } = usePage<{ auth: Auth }>().props;
    const isAdmin = auth.user.account_type === 'admin';
    const isRestaurant = auth.user.account_type === 'restaurant';
    const isWaiter = auth.user.account_type === 'waiter';
    const isCustomer = auth.user.account_type === 'customer';

    // The restaurant's screens, each shown when the account may at least read it
    // (the owner reaches all; staff what was granted to them).
    // An item shows when the account reaches any of its features.
    const staffNavItems: (NavItem & {
        feature: StaffFeature | StaffFeature[];
    })[] = [
        {
            feature: 'dashboard',
            title: t('nav.dashboards'),
            href: DashboardController.index.url(),
            icon: BarChart3,
        },
        {
            feature: 'tables',
            title: t('nav.tables'),
            href: TableController.index.url(),
            icon: UtensilsCrossed,
        },
        {
            feature: 'menus',
            title: t('nav.menus'),
            href: MenuController.index.url(),
            icon: ScrollText,
        },
        {
            // Staff who only mark products sold out reach the list too.
            feature: ['products', 'availability'],
            title: t('nav.products'),
            href: ProductController.index.url(),
            icon: ShoppingBag,
        },
        {
            feature: 'queues',
            title: t('nav.queues'),
            href: QueueController.index.url(),
            icon: ListOrdered,
        },
        {
            feature: 'staff',
            title: t('nav.waiters'),
            href: WaiterController.index.url(),
            icon: Users,
        },
        {
            feature: 'sales',
            title: t('nav.sales'),
            href: SaleController.index.url(),
            icon: Percent,
        },
        {
            feature: 'coupons',
            title: t('nav.coupons'),
            href: CouponController.index.url(),
            icon: BadgePercent,
        },
        {
            feature: 'calls',
            title: t('nav.calls'),
            href: CallController.index.url(),
            icon: BellRing,
            badge: auth.openCalls ?? 0,
        },
        {
            feature: 'delivery',
            title: t('nav.delivery'),
            href: DeliveryController.index.url(),
            icon: ConciergeBell,
        },
        {
            feature: 'incentives',
            title: t('nav.incentives'),
            href: IncentiveController.index.url(),
            icon: Gift,
        },
        {
            feature: 'establishment',
            title: t('nav.establishment'),
            href: EstablishmentController.edit.url(),
            icon: Store,
        },
        {
            feature: 'finance',
            title: t('nav.finance'),
            href: FinanceController.show.url(),
            icon: Landmark,
        },
    ];
    const allowedStaffItems: NavItem[] = staffNavItems.filter((item) =>
        (Array.isArray(item.feature) ? item.feature : [item.feature]).some(
            (feature) => (auth.permissions?.[feature] ?? 'none') !== 'none',
        ),
    );

    const mainNavItems: NavItem[] = isAdmin
        ? [
              {
                  title: t('nav.admin_overview'),
                  href: OverviewController.index.url(),
                  icon: Gauge,
              },
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
              {
                  title: t('nav.restaurants'),
                  href: AdminRestaurantController.index.url(),
                  icon: Store,
              },
              {
                  title: t('nav.users'),
                  href: AdminAccountController.index.url(),
                  icon: UserCog,
              },
              {
                  title: t('nav.tiers'),
                  href: FeeTierController.index.url(),
                  icon: Layers,
              },
              {
                  title: t('nav.coupons'),
                  href: AdminCouponController.index.url(),
                  icon: BadgePercent,
              },
              {
                  title: t('nav.payouts'),
                  href: AdminPayoutController.index.url(),
                  icon: HandCoins,
              },
              {
                  title: t('nav.admins'),
                  href: AdminUserController.index.url(),
                  icon: ShieldCheck,
              },
          ]
        : isRestaurant
          ? [
                ...allowedStaffItems,
                {
                    title: t('nav.feed'),
                    href: SocialFeedController.index.url(),
                    icon: Newspaper,
                },
            ]
          : isWaiter
            ? allowedStaffItems
            : isCustomer
              ? [
                    {
                        title: t('nav.feed'),
                        href: SocialFeedController.index.url(),
                        icon: Newspaper,
                    },
                    {
                        title: t('nav.discover'),
                        href: DiscoverController.index.url(),
                        icon: Compass,
                    },
                    {
                        title: t('nav.table'),
                        href: CustomerJoinController.index.url(),
                        icon: Utensils,
                    },
                    {
                        title: t('nav.rewards'),
                        href: CustomerRewardController.index.url(),
                        icon: Trophy,
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
                                        ? OverviewController.index.url()
                                        : isRestaurant
                                          ? ProductController.index.url()
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

            <SidebarContent className="scrollbar-sidebar pt-2">
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter className="border-t border-dashed border-sidebar-border pt-3">
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
