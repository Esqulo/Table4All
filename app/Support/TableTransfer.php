<?php

namespace App\Support;

use App\Enums\OnlinePaymentStatus;
use App\Enums\QueueItemStatus;
use App\Enums\TableCustomerStatus;
use App\Models\RestaurantTable;
use App\Models\TableCall;
use Illuminate\Support\Facades\DB;

/**
 * Moving consumption between two open tables of a restaurant: some items when a customer
 * changes tables, or everything when two groups join (the table left behind is removed).
 *
 * Both table rows are locked in one transaction before anything is read or written
 * (docs/performance.md, rule 7), always lowest id first, so two transfers between the same
 * tables in opposite directions wait for each other instead of deadlocking. Deliveries,
 * order saves, payments and closing lock the same rows, so none of them sees half a move.
 */
final class TableTransfer
{
    public const MOVED = 'moved';

    /** One of the tables was closed or removed meanwhile. */
    public const CLOSED = 'closed';

    /** The items asked for are no longer there (delivered, moved or edited meanwhile). */
    public const STALE = 'stale';

    /** A customer is paying the table being merged online: it would be credited to the wrong bill. */
    public const PAYMENT_IN_FLIGHT = 'payment_in_flight';

    /**
     * Moves units of delivered lines (per product and price) and whole open queue items
     * from $from to $to. All or nothing: if any of it is gone, nothing moves.
     *
     * Units keep the customer who ordered them; unattributed units go first.
     *
     * @param  list<array{product_id: int, price: float, quantity: int}>  $lines
     * @param  list<int>  $queueItemIds
     * @return self::MOVED|self::CLOSED|self::STALE
     */
    public static function moveItems(RestaurantTable $from, RestaurantTable $to, array $lines, array $queueItemIds): string
    {
        return DB::transaction(function () use ($from, $to, $lines, $queueItemIds): string {
            if (! self::lockOpenPair($from, $to)) {
                return self::CLOSED;
            }

            // Everything is checked before anything is written.
            $plan = [];

            foreach (self::sumByLine($lines) as $line) {
                $rows = DB::table('restaurant_table_product')
                    ->where('restaurant_table_id', $from->id)
                    ->where('product_id', $line['product_id'])
                    ->where('price', $line['price'])
                    ->orderByRaw('ordered_by_user_id IS NOT NULL')
                    ->orderBy('ordered_by_user_id')
                    ->select(['product_id', 'ordered_by_user_id', 'price', 'quantity'])
                    // The table has no id column: rows are addressed by their physical id,
                    // stable while the table row is locked and each row is touched once.
                    ->selectRaw('ctid::text as row_ref')
                    ->get();

                if ((int) $rows->sum('quantity') < $line['quantity']) {
                    return self::STALE;
                }

                $left = $line['quantity'];

                foreach ($rows as $row) {
                    if ($left <= 0) {
                        break;
                    }

                    $take = min((int) $row->quantity, $left);
                    $left -= $take;
                    $plan[] = [$row, $take];
                }
            }

            $queueItemIds = array_values(array_unique($queueItemIds));

            if ($queueItemIds !== []) {
                $movable = DB::table('queue_items')
                    ->where('restaurant_table_id', $from->id)
                    ->whereIn('id', $queueItemIds)
                    ->whereIn('status', QueueItemStatus::open())
                    ->count();

                if ($movable !== count($queueItemIds)) {
                    return self::STALE;
                }
            }

            foreach ($plan as [$row, $take]) {
                self::takeFromRow($row, $take);
                self::addToLine($to->id, (int) $row->product_id, $row->ordered_by_user_id === null ? null : (int) $row->ordered_by_user_id, (string) $row->price, $take);
            }

            if ($queueItemIds !== []) {
                DB::table('queue_items')
                    ->where('restaurant_table_id', $from->id)
                    ->whereIn('id', $queueItemIds)
                    ->update(['restaurant_table_id' => $to->id, 'updated_at' => now()]);
            }

            return self::MOVED;
        });
    }

    /**
     * Brings everything of $from into $to and removes $from: delivered lines, queue items
     * (open and past), payments and the customers at it. The chat isn't merged: both
     * tables' conversations start over, as the people at the merged table are a new group.
     * $to keeps its own
     * code, waiter, responsible (unless it had none) and service fee.
     *
     * @return self::MOVED|self::CLOSED|self::PAYMENT_IN_FLIGHT
     */
    public static function merge(RestaurantTable $from, RestaurantTable $to): string
    {
        return DB::transaction(function () use ($from, $to): string {
            if (! self::lockOpenPair($from, $to)) {
                return self::CLOSED;
            }

            $inFlight = DB::table('online_payments')
                ->where('restaurant_table_id', $from->id)
                ->whereIn('status', OnlinePaymentStatus::inFlight())
                ->exists();

            if ($inFlight) {
                return self::PAYMENT_IN_FLIGHT;
            }

            $now = now();

            // Few rows per table: one statement each keeps the NULL-safe matching simple.
            $lines = DB::table('restaurant_table_product')
                ->where('restaurant_table_id', $from->id)
                ->get(['product_id', 'ordered_by_user_id', 'price', 'quantity']);

            foreach ($lines as $row) {
                self::addToLine($to->id, (int) $row->product_id, $row->ordered_by_user_id === null ? null : (int) $row->ordered_by_user_id, (string) $row->price, (int) $row->quantity);
            }

            DB::table('restaurant_table_product')->where('restaurant_table_id', $from->id)->delete();

            foreach (['queue_items', 'table_payments', 'online_payments'] as $related) {
                DB::table($related)->where('restaurant_table_id', $from->id)->update(['restaurant_table_id' => $to->id]);
            }

            // A new group at the merged table: its conversation starts over (both tables').
            foreach (['table_messages', 'table_reactions'] as $chat) {
                DB::table($chat)->whereIn('restaurant_table_id', [$from->id, $to->id])->delete();
            }

            // The customers come along; someone let in at either table is let in at the merged one.
            DB::statement(
                'INSERT INTO restaurant_table_customers (restaurant_table_id, user_id, status, rejections, created_at, updated_at)
                 SELECT ?, user_id, status, rejections, created_at, ?
                 FROM restaurant_table_customers WHERE restaurant_table_id = ?
                 ON CONFLICT (restaurant_table_id, user_id) DO UPDATE
                 SET status = EXCLUDED.status, updated_at = EXCLUDED.updated_at
                 WHERE EXCLUDED.status = ? AND restaurant_table_customers.status <> ?',
                [$to->id, $now, $from->id, TableCustomerStatus::APPROVED->value, TableCustomerStatus::APPROVED->value],
            );
            DB::table('restaurant_table_customers')->where('restaurant_table_id', $from->id)->delete();

            $responsible = DB::table('restaurant_tables')->where('id', $to->id)->value('responsible_user_id');
            $fromResponsible = DB::table('restaurant_tables')->where('id', $from->id)->value('responsible_user_id');

            if ($responsible === null && $fromResponsible !== null) {
                DB::table('restaurant_tables')->where('id', $to->id)->update(['responsible_user_id' => $fromResponsible]);
            }

            // Nobody is left at the old table: its calls go, and so does the table (its code too).
            TableCall::resolve($from->id, TableCall::CLOSED);
            DB::table('restaurant_tables')->where('id', $from->id)->update([
                'access_code' => null,
                'responsible_user_id' => null,
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);

            return self::MOVED;
        });
    }

    /**
     * Locks both rows, lowest id first, and says whether both are still open.
     */
    private static function lockOpenPair(RestaurantTable $from, RestaurantTable $to): bool
    {
        $open = RestaurantTable::whereIn('id', [$from->id, $to->id])
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'closed_at']);

        return $open->count() === 2 && $open->every(fn (RestaurantTable $t): bool => $t->closed_at === null);
    }

    /**
     * Requested units summed per (product, price): the same line sent twice can't move twice.
     *
     * @param  list<array{product_id: int, price: float, quantity: int}>  $lines
     * @return list<array{product_id: int, price: string, quantity: int}>
     */
    private static function sumByLine(array $lines): array
    {
        $summed = [];

        foreach ($lines as $line) {
            $price = number_format($line['price'], 2, '.', '');
            $key = $line['product_id'].'|'.$price;
            $summed[$key] ??= ['product_id' => $line['product_id'], 'price' => $price, 'quantity' => 0];
            $summed[$key]['quantity'] += $line['quantity'];
        }

        return array_values($summed);
    }

    private static function takeFromRow(\stdClass $row, int $quantity): void
    {
        if ($quantity >= (int) $row->quantity) {
            DB::delete('DELETE FROM restaurant_table_product WHERE ctid = ?::tid', [$row->row_ref]);
        } else {
            DB::update('UPDATE restaurant_table_product SET quantity = quantity - ? WHERE ctid = ?::tid', [$quantity, $row->row_ref]);
        }
    }

    /**
     * Adds units to the table's line for (product, customer, price), or starts it. Only one
     * row grows even if the table has duplicates of an unattributed line (NULLs don't collide
     * in the unique key), so no unit is counted twice.
     */
    private static function addToLine(int $tableId, int $productId, ?int $orderedBy, string $price, int $quantity): void
    {
        $updated = DB::update(
            'UPDATE restaurant_table_product SET quantity = quantity + ?
             WHERE ctid = (
                 SELECT ctid FROM restaurant_table_product
                 WHERE restaurant_table_id = ? AND product_id = ? AND price = ?
                   AND ordered_by_user_id IS NOT DISTINCT FROM ?
                 LIMIT 1
             )',
            [$quantity, $tableId, $productId, $price, $orderedBy],
        );

        if ($updated === 0) {
            DB::table('restaurant_table_product')->insert([
                'restaurant_table_id' => $tableId,
                'product_id' => $productId,
                'ordered_by_user_id' => $orderedBy,
                'price' => $price,
                'quantity' => $quantity,
            ]);
        }
    }
}
