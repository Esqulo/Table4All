<?php

namespace App\Http\Controllers\Restaurant;

use App\Enums\QueueItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\QueueRequest;
use App\Models\QueueItem;
use App\Models\RestaurantQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QueueController extends Controller
{
    public function index(Request $request): Response
    {
        $queues = RestaurantQueue::where('user_id', $request->user()->id)
            ->orderBy('name')
            ->withCount('products')
            ->get();

        return Inertia::render('restaurant/queues', [
            'queues' => $queues,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('restaurant/queues/create');
    }

    public function store(QueueRequest $request): RedirectResponse
    {
        RestaurantQueue::create([
            'user_id' => $request->user()->id,
            'name'    => $request->validated('name'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'queues.msg_created']);

        return to_route('restaurant.queues.index');
    }

    public function show(Request $request, RestaurantQueue $queue): Response
    {
        abort_unless($queue->user_id === $request->user()->id, 403);

        $items = QueueItem::where('queue_id', $queue->id)
            ->whereIn('status', [QueueItemStatus::PENDING->value, QueueItemStatus::DONE->value])
            ->with([
                'product:id,name,picture,price,price_type',
                'restaurantTable:id,title',
            ])
            ->orderBy('created_at')
            ->get();

        return Inertia::render('restaurant/queues/show', [
            'queue' => $queue,
            'items' => $items,
        ]);
    }

    public function edit(Request $request, RestaurantQueue $queue): Response
    {
        abort_unless($queue->user_id === $request->user()->id, 403);

        return Inertia::render('restaurant/queues/edit', [
            'queue' => $queue,
        ]);
    }

    public function update(QueueRequest $request, RestaurantQueue $queue): RedirectResponse
    {
        abort_unless($queue->user_id === $request->user()->id, 403);

        $queue->update(['name' => $request->validated('name')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'queues.msg_updated']);

        return to_route('restaurant.queues.index');
    }

    public function destroy(Request $request, RestaurantQueue $queue): RedirectResponse
    {
        abort_unless($queue->user_id === $request->user()->id, 403);

        $queue->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'queues.msg_deleted']);

        return to_route('restaurant.queues.index');
    }
}