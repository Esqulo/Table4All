<?php

namespace App\Support;

use App\Enums\TableCustomerStatus;
use App\Models\RestaurantTable;
use Illuminate\Support\Facades\DB;

/** Who is at a table: its responsible and the customers they let in. */
final class TableMembership
{
    public static function isMember(RestaurantTable $table, int $userId): bool
    {
        return $table->responsible_user_id === $userId
            || DB::table('restaurant_table_customers')
                ->where('restaurant_table_id', $table->id)
                ->where('user_id', $userId)
                ->where('status', TableCustomerStatus::APPROVED->value)
                ->exists();
    }
}
