import { usePage } from '@inertiajs/react';
import type { Auth } from '@/types';

/** Restaurant features whose access is granted per staff member (App\Enums\StaffFeature). */
export type StaffFeature =
    | 'tables'
    | 'delivery'
    | 'queues'
    | 'products'
    | 'menus'
    | 'sales'
    | 'coupons'
    | 'incentives'
    | 'dashboard'
    | 'finance'
    | 'establishment'
    | 'staff';

export type PermissionLevel = 'none' | 'read' | 'write';

/**
 * How far the signed-in account goes in a feature of its restaurant (the owner writes
 * everywhere). Only hides or disables controls: the server enforces it on every route.
 */
export function usePermission(feature: StaffFeature): PermissionLevel {
    const { auth } = usePage<{ auth: Auth }>().props;

    return auth.permissions?.[feature] ?? 'none';
}

/** The account may change data in this feature (otherwise its screens are read-only). */
export function useCanWrite(feature: StaffFeature): boolean {
    return usePermission(feature) === 'write';
}
