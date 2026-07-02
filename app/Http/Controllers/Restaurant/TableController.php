<?php

namespace App\Http\Controllers\Restaurant;

use App\Enums\PaymentMethod;
use App\Enums\QueueItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\TableRequest;
use App\Models\Product;
use App\Models\QueueItem;
use App\Models\RestaurantTable;
use App\Models\Sale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class TableController extends Controller
{
    public function index(Request $request): Response
    {
        $tables = RestaurantTable::where('user_id', $request->user()->effectiveRestaurantId())
            ->whereNull('closed_at')
            ->with([
                'products' => fn ($q) => $q
                    ->select('products.id', 'products.name', 'products.picture', 'products.price', 'products.price_type')
                    ->withPivot('quantity'),
                'payments:id,restaurant_table_id,method,amount',
            ])
            ->withCount('products')
            ->orderBy('title')
            ->get();

        return Inertia::render('restaurant/tables', [
            'tables' => $tables,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('restaurant/tables/create');
    }

    public function store(TableRequest $request): RedirectResponse
    {
        RestaurantTable::create([
            'user_id' => $request->user()->effectiveRestaurantId(),
            'title'   => $request->validated('title'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'tables.msg_created']);

        return to_route('restaurant.tables.index');
    }

    public function show(Request $request, RestaurantTable $table): Response|SymfonyResponse
    {
        abort_unless($table->user_id === $request->user()->effectiveRestaurantId(), 403);

        if ($table->closed_at !== null) {
            return Inertia::location(route('restaurant.tables.index'));
        }

        $table->load([
            'payments' => fn ($q) => $q->with('registeredBy:id,name'),
        ]);

        $products = Product::where('user_id', $request->user()->effectiveRestaurantId())
            ->with('queue:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'picture', 'price', 'price_type', 'queue_id']);

        // Raw query so the same product can appear on multiple rows at different prices.
        // Eloquent BelongsToMany de-duplicates on product_id and would collapse them.
        $orderLines = DB::table('restaurant_table_product')
            ->where('restaurant_table_id', $table->id)
            ->join('products', 'products.id', '=', 'restaurant_table_product.product_id')
            ->select(
                'products.id',
                'products.name',
                'products.picture',
                'products.price_type',
                'restaurant_table_product.price',
                'restaurant_table_product.quantity',
            )
            ->get()
            ->map(fn ($row) => [
                'id'          => $row->id,
                'name'        => $row->name,
                'picture'     => $row->picture,
                'picture_url' => $row->picture ? Storage::disk('public')->url($row->picture) : null,
                'price_type'  => $row->price_type,
                'price'       => (float) $row->price,
                'quantity'    => (int) $row->quantity,
            ]);

        $now         = now();
        $currentDay  = (int) $now->dayOfWeek;
        $currentTime = $now->format('H:i:s');

        $activeSales = Sale::where('user_id', $request->user()->effectiveRestaurantId())
            ->whereJsonContains('days', [$currentDay])
            ->where('start_time', '<=', $currentTime)
            ->where('end_time', '>=', $currentTime)
            ->get(['product_id', 'sale_price']);

        $queueItems = QueueItem::where('restaurant_table_id', $table->id)
            ->whereIn('status', [QueueItemStatus::PENDING->value, QueueItemStatus::DONE->value])
            ->with([
                'product:id,name,picture,price,price_type',
                'queue:id,name',
            ])
            ->orderBy('created_at')
            ->get();

        return Inertia::render('restaurant/tables/show', [
            'table'       => $table,
            'products'    => $products,
            'orderLines'  => $orderLines,
            'activeSales' => $activeSales,
            'queueItems'  => $queueItems,
        ]);
    }

    public function edit(Request $request, RestaurantTable $table): Response
    {
        abort_unless($table->user_id === $request->user()->effectiveRestaurantId(), 403);

        return Inertia::render('restaurant/tables/edit', [
            'table' => $table,
        ]);
    }

    public function update(TableRequest $request, RestaurantTable $table): RedirectResponse
    {
        abort_unless($table->user_id === $request->user()->effectiveRestaurantId(), 403);

        if ($request->has('title')) {
            $table->update(['title' => $request->validated('title')]);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'tables.msg_updated']);
            return to_route('restaurant.tables.index');
        }

        $this->syncOrder($request, $table);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'tables.msg_products_updated']);
        return to_route('restaurant.tables.show', $table);
    }

    public function addPayment(Request $request, RestaurantTable $table): RedirectResponse
    {
        abort_unless($table->user_id === $request->user()->effectiveRestaurantId(), 403);
        abort_if($table->closed_at !== null, 422, 'Table is already closed.');

        $validated = $request->validate([
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
        ]);

        $table->payments()->create([
            'method'             => $validated['method'],
            'amount'             => $validated['amount'],
            'registered_by_id'   => $request->user()->id,
            'registered_by_type' => $request->user()->account_type->value,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'tables.msg_payment_added']);
        return to_route('restaurant.tables.show', $table);
    }

    public function close(Request $request, RestaurantTable $table): RedirectResponse
    {
        abort_unless($table->user_id === $request->user()->effectiveRestaurantId(), 403);
        abort_if($table->closed_at !== null, 422, 'Table is already closed.');

        $productTotal = (float) DB::table('restaurant_table_product')
            ->where('restaurant_table_id', $table->id)
            ->sum(DB::raw('price * quantity'));

        $paymentTotal = (float) $table->payments()->sum('amount');

        if ($paymentTotal < $productTotal) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'tables.msg_insufficient_payment']);
            return to_route('restaurant.tables.show', $table);
        }

        $table->closed_at = now();
        $table->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'tables.msg_closed']);
        return to_route('restaurant.tables.index');
    }

    public function destroy(Request $request, RestaurantTable $table): RedirectResponse
    {
        abort_unless($table->user_id === $request->user()->effectiveRestaurantId(), 403);

        $table->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'tables.msg_deleted']);
        return to_route('restaurant.tables.index');
    }

    private function syncOrder(TableRequest $request, RestaurantTable $table): void
    {
        $ownerId  = $request->user()->effectiveRestaurantId();
        $validIds = Product::where('user_id', $ownerId)->pluck('id')->toArray();

        // Full replace: delete all existing pivot rows then re-insert with explicit prices.
        $submitted = $request->input('products', []);

        DB::table('restaurant_table_product')
            ->where('restaurant_table_id', $table->id)
            ->delete();

        $rows = [];
        foreach ($submitted as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty       = (int) ($item['quantity'] ?? 0);
            $price     = (float) ($item['price'] ?? 0);

            if (!in_array($productId, $validIds, true) || $qty <= 0 || $price < 0) {
                continue;
            }

            $rows[] = [
                'restaurant_table_id' => $table->id,
                'product_id'          => $productId,
                'quantity'            => $qty,
                'price'               => $price,
            ];
        }

        if (!empty($rows)) {
            DB::table('restaurant_table_product')->insert($rows);
        }

        $queueAdditions = $request->input('queue_additions', []);
        if (empty($queueAdditions)) {
            return;
        }

        $queueProductIds = array_column($queueAdditions, 'product_id');
        $queueProducts   = Product::where('user_id', $ownerId)
            ->whereIn('id', $queueProductIds)
            ->whereNotNull('queue_id')
            ->get(['id', 'queue_id']);

        foreach ($queueProducts as $product) {
            $additionItem = collect($queueAdditions)->firstWhere('product_id', $product->id);
            $qty          = (int) ($additionItem['quantity'] ?? 0);
            $price        = (float) ($additionItem['price'] ?? 0);

            if ($qty <= 0) {
                continue;
            }

            QueueItem::create([
                'restaurant_table_id' => $table->id,
                'product_id'          => $product->id,
                'queue_id'            => $product->queue_id,
                'quantity'            => $qty,
                'price'               => $price,
                'status'              => QueueItemStatus::PENDING->value,
            ]);
        }
    }
}
