<?php

namespace App\Http\Controllers\Customer;

use App\Enums\QueueItemStatus;
use App\Http\Controllers\Controller;
use App\Models\QueueItem;
use App\Models\RestaurantTable;
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
            ->with(['user:id,name,avatar', 'payments'])
            ->first();

        if (! $table) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'join_table.invalid_code']);

            return redirect()->route('customer.join');
        }

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
                'name'  => $item->product->name,
                'price' => (float) $item->price,
            ]);

        $total = (float) DB::table('restaurant_table_product')
            ->where('restaurant_table_id', $table->id)
            ->sum(DB::raw('price * quantity'));

        $paid = (float) $table->payments->sum('amount');

        return Inertia::render('customer/show', [
            'products'   => $products,
            'preparing'  => $preparing,
            'total'      => $total,
            'paid'       => $paid,
            'remaining'  => max(0.0, $total - $paid),
        ]);
    }
}
