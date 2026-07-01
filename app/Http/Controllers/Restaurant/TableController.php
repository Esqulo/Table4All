<?php

namespace App\Http\Controllers\Restaurant;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\TableRequest;
use App\Models\Product;
use App\Models\RestaurantTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                    ->select('products.id', 'name', 'picture', 'price', 'price_type')
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
            'products' => fn ($q) => $q
                ->select('products.id', 'name', 'picture', 'price', 'price_type')
                ->withPivot('quantity'),
            'payments' => fn ($q) => $q->with('registeredBy:id,name'),
        ]);

        $products = Product::where('user_id', $request->user()->effectiveRestaurantId())
            ->orderBy('name')
            ->get(['id', 'name', 'picture', 'price', 'price_type']);

        return Inertia::render('restaurant/tables/show', [
            'table'    => $table,
            'products' => $products,
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
            ->join('products', 'products.id', '=', 'restaurant_table_product.product_id')
            ->where('restaurant_table_product.restaurant_table_id', $table->id)
            ->sum(DB::raw('products.price * restaurant_table_product.quantity'));

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
        $submitted = $request->input('products', []);

        $validIds = Product::where('user_id', $request->user()->effectiveRestaurantId())
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->toArray();

        $sync = [];
        foreach ($submitted as $productId => $quantity) {
            $qty = (int) $quantity;
            if (in_array((string) $productId, $validIds, true) && $qty > 0) {
                $sync[(int) $productId] = ['quantity' => $qty];
            }
        }

        $table->products()->sync($sync);
    }
}
