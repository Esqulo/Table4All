<?php

namespace App\Http\Controllers\Restaurant;

use App\Enums\QueueItemStatus;
use App\Http\Controllers\Controller;
use App\Models\QueueItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class DeliveryController extends Controller
{
    public function index(Request $request): Response
    {
        $restaurantId = $request->user()->effectiveRestaurantId();

        $items = QueueItem::whereHas(
            'restaurantTable',
            fn ($q) => $q->where('user_id', $restaurantId)
        )
            ->where(function ($q) {
                // Kitchen items flagged as done by the cook
                $q->where('status', QueueItemStatus::DONE->value)
                  ->orWhere(function ($inner) {
                      // Non-kitchen customer orders waiting for a waiter
                      $inner->where('status', QueueItemStatus::PENDING->value)
                            ->whereNull('queue_id');
                  });
            })
            ->with([
                'product:id,name,picture',
                'restaurantTable:id,title',
                'orderedBy:id,name,email',
            ])
            ->oldest('updated_at')
            ->get()
            ->map(fn ($item) => [
                'id'          => $item->id,
                'table_id'    => $item->restaurant_table_id,
                'table_title' => $item->restaurantTable->title,
                'product'     => $item->product->name,
                'picture_url' => $item->product->picture
                    ? Storage::disk('public')->url($item->product->picture)
                    : null,
                'quantity'    => $item->quantity,
                'price'       => (float) $item->price,
                'status'      => $item->status->value,
                'ordered_by'  => $item->orderedBy?->name ?? $item->orderedBy?->email ?? null,
                'waiting_since' => $item->updated_at->toISOString(),
            ]);

        return Inertia::render('restaurant/delivery/index', [
            'items' => $items,
        ]);
    }
}
