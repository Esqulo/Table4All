<?php

namespace App\Http\Controllers\Customer;

use App\Enums\QueueItemStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\QueueItem;
use App\Models\RestaurantTable;
use Illuminate\Http\Request;
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
                'price'         => (float) $p->price,
                'price_type'    => $p->price_type,
                'has_queue'     => $p->queue_id !== null,
                'picture_url'   => $p->picture ? Storage::disk('public')->url($p->picture) : null,
            ]);

        $products = DB::table('restaurant_table_product')
            ->where('restaurant_table_id', $table->id)
            ->join('products', 'products.id', '=', 'restaurant_table_product.product_id')
            ->select(
                'products.id',
                'products.name',
                'products.description',
                'products.picture',
                'products.price_type',
                'restaurant_table_product.price',
                'restaurant_table_product.quantity',
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
            ]);

        $preparing = QueueItem::where('restaurant_table_id', $table->id)
            ->whereIn('status', [QueueItemStatus::PENDING->value, QueueItemStatus::DONE->value])
            ->with('product:id,name')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($item) => [
                'name'     => $item->product->name,
                'price'    => (float) $item->price,
                'quantity' => (int) $item->quantity,
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

        $productIds = array_column($validated['items'], 'product_id');
        $products   = Product::where('user_id', $table->user_id)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        foreach ($validated['items'] as $item) {
            $product  = $products->get($item['product_id']);
            $quantity = $item['quantity'];

            if (! $product) {
                continue;
            }

            if ($product->queue_id) {
                QueueItem::create([
                    'restaurant_table_id' => $table->id,
                    'product_id'          => $product->id,
                    'queue_id'            => $product->queue_id,
                    'quantity'            => $quantity,
                    'price'               => (float) $product->price,
                    'status'              => QueueItemStatus::PENDING->value,
                ]);
            } else {
                $affected = DB::table('restaurant_table_product')
                    ->where('restaurant_table_id', $table->id)
                    ->where('product_id', $product->id)
                    ->increment('quantity', $quantity);

                if ($affected === 0) {
                    DB::table('restaurant_table_product')->insert([
                        'restaurant_table_id' => $table->id,
                        'product_id'          => $product->id,
                        'quantity'            => $quantity,
                        'price'               => (float) $product->price,
                    ]);
                }
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'join_table.order_success']);

        return redirect()->route('customer.table', $code);
    }
}
