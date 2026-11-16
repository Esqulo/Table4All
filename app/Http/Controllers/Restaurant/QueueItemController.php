<?php

namespace App\Http\Controllers\Restaurant;

use App\Enums\QueueItemStatus;
use App\Http\Controllers\Controller;
use App\Models\QueueItem;
use App\Models\RestaurantTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Status transitions are conditional UPDATEs ("... WHERE status = x"), so a
 * double tap or two staff members hitting the same card at once can only
 * apply the transition once. Without that, two concurrent "delivered"
 * requests both passed the status check and billed the item twice.
 */
class QueueItemController extends Controller
{
    public function markDone(Request $request, QueueItem $queueItem): RedirectResponse
    {
        $this->authorizeItem($request, $queueItem);

        $changed = QueueItem::whereKey($queueItem->id)
            ->where('status', QueueItemStatus::PENDING->value)
            ->update(['status' => QueueItemStatus::DONE->value, 'updated_at' => now()]);

        if ($changed > 0) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'queues.item_done']);
        }

        return redirect()->back();
    }

    public function markDelivered(Request $request, QueueItem $queueItem): RedirectResponse
    {
        $this->authorizeItem($request, $queueItem);

        $delivered = DB::transaction(function () use ($queueItem): bool {
            // Serializes with TableController::syncOrder on the same table, so a
            // delivery can't land between the waiter's delete and re-insert.
            RestaurantTable::whereKey($queueItem->restaurant_table_id)->lockForUpdate()->first(['id']);

            $claimed = QueueItem::whereKey($queueItem->id)
                ->where('status', '!=', QueueItemStatus::DELIVERED->value)
                ->update(['status' => QueueItemStatus::DELIVERED->value, 'updated_at' => now()]);

            if ($claimed === 0) {
                return false;
            }

            $line = DB::table('restaurant_table_product')
                ->where('restaurant_table_id', $queueItem->restaurant_table_id)
                ->where('product_id', $queueItem->product_id)
                ->where('ordered_by_user_id', $queueItem->ordered_by_user_id)
                // Same product at a different price (promotion) is a separate line.
                ->where('price', (float) $queueItem->price);

            if ($line->increment('quantity', (int) $queueItem->quantity) === 0) {
                DB::table('restaurant_table_product')->insert([
                    'restaurant_table_id' => $queueItem->restaurant_table_id,
                    'product_id' => $queueItem->product_id,
                    'ordered_by_user_id' => $queueItem->ordered_by_user_id,
                    'price' => (float) $queueItem->price,
                    'quantity' => $queueItem->quantity,
                ]);
            }

            return true;
        });

        if ($delivered) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'queues.item_delivered']);
        }

        return redirect()->back();
    }

    private function authorizeItem(Request $request, QueueItem $queueItem): void
    {
        $ownerId = RestaurantTable::withTrashed()
            ->whereKey($queueItem->restaurant_table_id)
            ->value('user_id');

        abort_unless($ownerId !== null && (int) $ownerId === $request->user()->effectiveRestaurantId(), 403);
    }
}
