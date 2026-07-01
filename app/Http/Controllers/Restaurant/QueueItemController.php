<?php

namespace App\Http\Controllers\Restaurant;

use App\Enums\QueueItemStatus;
use App\Http\Controllers\Controller;
use App\Models\QueueItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $table = $queueItem->restaurantTable;
        $existingProduct = $table->products()->withPivot('quantity')->find($queueItem->product_id);

        if ($existingProduct) {
            $table->products()->updateExistingPivot($queueItem->product_id, [
                'quantity' => (int) $existingProduct->pivot->getAttribute('quantity') + $queueItem->quantity,
            ]);
        } else {
            $table->products()->attach($queueItem->product_id, [
                'quantity' => $queueItem->quantity,
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'queues.item_delivered']);

        return redirect()->back();
    }
}
