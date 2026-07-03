<?php

namespace App\Http\Controllers\Customer;

use App\Enums\QueueItemStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\QueueItem;
use App\Models\RestaurantTable;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

class JoinController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('customer/join');
    }

    public function show(string $code): Response|RedirectResponse
    {
        $table = RestaurantTable::where('access_code', $code)
            ->whereNull('closed_at')
            ->whereNull('deleted_at')
            ->with(['payments'])
            ->first();

        if (! $table) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'join_table.invalid_code']);

            return redirect()->route('customer.join');
        }

        $activeSales = $this->activeSalesFor($table->user_id);

        $menu = Product::where('user_id', $table->user_id)
            ->with('category:id,name')
            ->orderBy('name')
            ->get(['id', 'category_id', 'name', 'description', 'picture', 'price', 'price_type', 'queue_id'])
            ->map(fn ($p) => [
                'id'            => $p->id,
                'category_id'   => $p->category_id,
                'category_name' => $p->category?->name,
                'name'          => $p->name,
                'description'   => $p->description,
                'price'         => (float) ($activeSales[$p->id] ?? $p->price),
                'price_type'    => $p->price_type,
                'has_queue'     => $p->queue_id !== null,
                'picture_url'   => $p->picture ? Storage::disk('public')->url($p->picture) : null,
            ]);

        $products = DB::table('restaurant_table_product')
            ->where('restaurant_table_id', $table->id)
            ->join('products', 'products.id', '=', 'restaurant_table_product.product_id')
            ->leftJoin('users', 'users.id', '=', 'restaurant_table_product.ordered_by_user_id')
            ->select(
                'products.id',
                'products.name',
                'products.description',
                'products.picture',
                'products.price_type',
                'restaurant_table_product.price',
                'restaurant_table_product.quantity',
                'users.name as ordered_by_name',
                'users.email as ordered_by_email',
            )
            ->get()
            ->map(fn ($row) => [
                'id'          => $row->id,
                'name'        => $row->name,
                'description' => $row->description,
                'price'       => (float) $row->price,
                'price_type'  => $row->price_type,
                'quantity'    => (int) $row->quantity,
                'picture_url' => $row->picture ? Storage::disk('public')->url($row->picture) : null,
                'ordered_by'  => $row->ordered_by_name ?? $row->ordered_by_email ?? null,
            ]);

        $preparing = QueueItem::where('restaurant_table_id', $table->id)
            ->whereIn('status', [QueueItemStatus::PENDING->value, QueueItemStatus::DONE->value])
            ->with(['product:id,name', 'orderedBy:id,name,email'])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($item) => [
                'name'       => $item->product->name,
                'price'      => (float) $item->price,
                'quantity'   => (int) $item->quantity,
                'has_queue'  => $item->queue_id !== null,
                'ordered_by' => $item->orderedBy !== null ? ($item->orderedBy->name ?? $item->orderedBy->email) : null,
            ]);

        $total = (float) DB::table('restaurant_table_product')
            ->where('restaurant_table_id', $table->id)
            ->sum(DB::raw('price * quantity'));

        $paid = (float) $table->payments->sum('amount');

        return Inertia::render('customer/show', [
            'code'      => $code,
            'menu'      => $menu,
            'products'  => $products,
            'preparing' => $preparing,
            'total'     => $total,
            'paid'      => $paid,
            'remaining' => max(0.0, $total - $paid),
        ]);
    }

    public function order(Request $request, string $code): RedirectResponse
    {
        $table = RestaurantTable::where('access_code', $code)
            ->whereNull('closed_at')
            ->whereNull('deleted_at')
            ->firstOrFail();

        $validated = $request->validate([
            'items'                => ['required', 'array', 'min:1'],
            'items.*.product_id'   => ['required', 'integer'],
            'items.*.quantity'     => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $productIds  = array_column($validated['items'], 'product_id');
        $products    = Product::where('user_id', $table->user_id)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');
        $activeSales = $this->activeSalesFor($table->user_id);
        $userId      = auth()->id();

        foreach ($validated['items'] as $item) {
            $product  = $products->get($item['product_id']);
            $quantity = $item['quantity'];

            if (! $product) {
                continue;
            }

            // All customer orders go through queue_items (queue_id is null for non-kitchen products).
            // Items reach restaurant_table_product only after a waiter marks them as delivered.
            QueueItem::create([
                'restaurant_table_id' => $table->id,
                'product_id'          => $product->id,
                'queue_id'            => $product->queue_id ?: null,
                'ordered_by_user_id'  => $userId,
                'quantity'            => $quantity,
                'price'               => (float) ($activeSales[$product->id] ?? $product->price),
                'status'              => QueueItemStatus::PENDING->value,
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'join_table.order_success']);

        return redirect()->route('customer.table', $code);
    }

    /**
     * Returns a map of product_id → sale_price for currently active sales.
     *
     * @return Collection<int, float>
     */
    private function activeSalesFor(int $restaurantUserId): Collection
    {
        $now         = now();
        $currentDay  = (int) $now->dayOfWeek;
        $currentTime = $now->format('H:i:s');
        $nowDatetime = $now->format('Y-m-d H:i:s');

        return Sale::where('user_id', $restaurantUserId)
            ->where(function ($q) use ($currentDay, $currentTime, $nowDatetime) {
                $q->where(function ($q) use ($currentDay, $currentTime) {
                    $q->where('type', 'periodic')
                      ->whereJsonContains('days', [$currentDay])
                      ->where('start_time', '<=', $currentTime)
                      ->where('end_time', '>=', $currentTime);
                })->orWhere(function ($q) use ($nowDatetime) {
                    $q->where('type', 'scheduled')
                      ->where('starts_at', '<=', $nowDatetime)
                      ->where('ends_at', '>=', $nowDatetime);
                });
            })
            ->pluck('sale_price', 'product_id');
    }
}
