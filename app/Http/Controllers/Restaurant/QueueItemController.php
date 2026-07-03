<?php

namespace App\Http\Controllers\Restaurant;

use App\Enums\QueueItemStatus;
use App\Http\Controllers\Controller;
use App\Models\QueueItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class QueueItemController extends Controller
{
    public function markDone(Request $request, QueueItem $queueItem): RedirectResponse
    {
        abort_unless(
            $queueItem->restaurantTable->user_id === $request->user()->effectiveRestaurantId(),
            403
        );

        if ($queueItem->status !== QueueItemStatus::PENDING) {
            return redirect()->back();
        }

        $queueItem->update(['status' => QueueItemStatus::DONE]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'queues.item_done']);

        return redirect()->back();
    }

    public function markDelivered(Request $request, QueueItem $queueItem): RedirectResponse
    {
        abort_unless(
            $queueItem->restaurantTable->user_id === $request->user()->effectiveRestaurantId(),
            403
        );

        if ($queueItem->status === QueueItemStatus::DELIVERED) {
            return redirect()->back();
        }

        $queueItem->update(['status' => QueueItemStatus::DELIVERED]);

        $table  = $queueItem->restaurantTable;
        $price  = (float) $queueItem->price;
        $userId = $queueItem->ordered_by_user_id;

        $query = DB::table('restaurant_table_product')
            ->where('restaurant_table_id', $table->id)
            ->where('product_id', $queueItem->product_id)
            ->where('ordered_by_user_id', $userId);

        $existing = $query->first();

        if ($existing) {
            $query->update(['quantity' => $existing->quantity + $queueItem->quantity]);
        } else {
            DB::table('restaurant_table_product')->insert([
                'restaurant_table_id' => $table->id,
                'product_id'          => $queueItem->product_id,
                'ordered_by_user_id'  => $userId,
                'price'               => $price,
                'quantity'            => $queueItem->quantity,
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'queues.item_delivered']);

        return redirect()->back();
    }
}
