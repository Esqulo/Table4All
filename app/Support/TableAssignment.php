<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Enums\Permission;
use App\Enums\StaffFeature;
use App\Models\RestaurantTable;
use App\Models\User;

/**
 * Which waiter is in charge of each table (restaurant_tables.waiter_id), when the
 * restaurant turned the feature on (users.table_assignment_enabled).
 *
 * - Whoever on the team opens a table gets it.
 * - A team member who works the tables takes a free table, and can let go of their own.
 * - The owner and managers assign any table to a team member, or free it.
 *
 * Changes are conditional updates, so two waiters taking the same table at once end
 * with one of them in charge, never a silent overwrite.
 */
final class TableAssignment
{
    public static function enabled(int $restaurantId): bool
    {
        return (bool) User::whereKey($restaurantId)->value('table_assignment_enabled');
    }

    /** The owner and managers assign tables to anyone on the team. */
    public static function canAssign(User $actor): bool
    {
        return $actor->account_type === AccountType::RESTAURANT || $actor->isManager();
    }

    /** Team members who work the tables take free tables and let go of their own. */
    public static function canServe(User $actor): bool
    {
        return $actor->isStaff() && $actor->access(StaffFeature::TABLES) === Permission::WRITE;
    }

    /** Whoever opened the table, when on the team and the feature is on. */
    public static function forNewTable(User $opener): ?int
    {
        return $opener->isStaff() && self::enabled($opener->effectiveRestaurantId()) ? $opener->id : null;
    }

    /** Takes a free table. False when someone else got it first. */
    public static function claim(RestaurantTable $table, User $waiter): bool
    {
        return RestaurantTable::whereKey($table->id)
            ->whereNull('closed_at')
            ->whereNull('waiter_id')
            ->update(['waiter_id' => $waiter->id, 'updated_at' => now()]) === 1;
    }

    /** Lets go of the waiter's own table; nothing happens if it's someone else's by now. */
    public static function release(RestaurantTable $table, User $waiter): bool
    {
        return RestaurantTable::whereKey($table->id)
            ->where('waiter_id', $waiter->id)
            ->update(['waiter_id' => null, 'updated_at' => now()]) === 1;
    }

    public static function assign(RestaurantTable $table, ?int $waiterId): void
    {
        RestaurantTable::whereKey($table->id)
            ->whereNull('closed_at')
            ->update(['waiter_id' => $waiterId, 'updated_at' => now()]);
    }

    /**
     * Team members a table can be given to: anyone who can at least see the tables.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function candidates(int $restaurantId): array
    {
        $members = User::where('restaurant_id', $restaurantId)
            ->where('account_type', AccountType::WAITER)
            ->orderBy('name')
            ->get(['id', 'name', 'account_type', 'restaurant_id', 'staff_role', 'staff_permissions'])
            ->filter(fn (User $member): bool => $member->access(StaffFeature::TABLES) !== Permission::NONE);

        $candidates = [];

        foreach ($members as $member) {
            $candidates[] = ['id' => $member->id, 'name' => $member->name];
        }

        return $candidates;
    }

    public static function isCandidate(int $restaurantId, int $memberId): bool
    {
        return in_array($memberId, array_column(self::candidates($restaurantId), 'id'), true);
    }

    /** Frees every open table of the restaurant (feature turned off) or of one member (left the team). */
    public static function clearOpen(int $restaurantId, ?int $waiterId = null): void
    {
        RestaurantTable::where('user_id', $restaurantId)
            ->whereNull('closed_at')
            ->when($waiterId !== null, fn ($q) => $q->where('waiter_id', $waiterId), fn ($q) => $q->whereNotNull('waiter_id'))
            ->update(['waiter_id' => null, 'updated_at' => now()]);
    }

    /**
     * What the tables screens need to show and offer.
     *
     * @return array{enabled: bool, canAssign: bool, canServe: bool, me: int}
     */
    public static function context(User $actor): array
    {
        $enabled = self::enabled($actor->effectiveRestaurantId());

        return [
            'enabled' => $enabled,
            'canAssign' => $enabled && self::canAssign($actor),
            'canServe' => $enabled && self::canServe($actor),
            'me' => $actor->id,
        ];
    }
}
