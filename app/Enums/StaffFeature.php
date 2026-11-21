<?php

namespace App\Enums;

/**
 * Restaurant features whose access is granted per staff member (none, read-only or write).
 * The routes say which feature they belong to (EnsureFeatureAccess); the owner always writes.
 *
 * Some features are read-only for staff whatever is granted: the venue's own profile and its
 * finances belong to the owner ("mexer no restaurante"), and the dashboard only shows data.
 * Posting on the social profile is owner-only and isn't a feature here at all.
 */
enum StaffFeature: string
{
    case TABLES = 'tables';
    case DELIVERY = 'delivery';
    /** The calls board: tables calling the waiter or asking for the bill. */
    case CALLS = 'calls';
    case QUEUES = 'queues';
    case PRODUCTS = 'products';
    /**
     * Marking products sold out ("esgotado") and back, without editing them: the kitchen
     * or the floor knows first when something runs out. Writing products covers it too.
     */
    case AVAILABILITY = 'availability';
    case MENUS = 'menus';
    case SALES = 'sales';
    case COUPONS = 'coupons';
    case INCENTIVES = 'incentives';
    case DASHBOARD = 'dashboard';
    case FINANCE = 'finance';
    case ESTABLISHMENT = 'establishment';
    /** The team: managers only (write), never a waiter; not granted, it follows the role. */
    case STAFF = 'staff';

    /** The most a staff member can have here, whatever is granted. */
    public function staffCeiling(): Permission
    {
        return match ($this) {
            self::DASHBOARD, self::FINANCE, self::ESTABLISHMENT => Permission::READ,
            default => Permission::WRITE,
        };
    }

    /** Whether the restaurant chooses the level per staff member (STAFF follows the role). */
    public function grantable(): bool
    {
        return $this !== self::STAFF;
    }

    /**
     * What a new waiter gets: the service screens, as before permissions existed.
     *
     * @return array<string, string>
     */
    public static function waiterDefaults(): array
    {
        return [
            self::TABLES->value => Permission::WRITE->value,
            self::DELIVERY->value => Permission::WRITE->value,
            self::CALLS->value => Permission::WRITE->value,
            self::PRODUCTS->value => Permission::READ->value,
            self::AVAILABILITY->value => Permission::WRITE->value,
        ];
    }

    /** @return list<self> */
    public static function grantableCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $f): bool => $f->grantable()));
    }
}
