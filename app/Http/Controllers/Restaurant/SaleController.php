<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\SaleRequest;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    public function index(Request $request): Response
    {
        $sales = Sale::where('user_id', $request->user()->id)
            ->with('product:id,name,price,price_type')
            ->orderBy('type')
            ->orderBy('start_time')
            ->orderBy('starts_at')
            ->get();

        return Inertia::render('restaurant/sales', [
            'sales' => $sales,
        ]);
    }

    public function create(Request $request): Response
    {
        $products = Product::where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'price_type']);

        return Inertia::render('restaurant/sales/create', [
            'products' => $products,
        ]);
    }

    public function store(SaleRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data = $this->normalizeByType($data);

        Sale::create([
            ...$data,
            'user_id' => $request->user()->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'sales.msg_created']);

        return to_route('restaurant.sales.index');
    }

    public function edit(Request $request, Sale $sale): Response
    {
        abort_unless($sale->user_id === $request->user()->id, 403);

        $products = Product::where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'price_type']);

        return Inertia::render('restaurant/sales/edit', [
            'sale'     => $sale,
            'products' => $products,
        ]);
    }

    public function update(SaleRequest $request, Sale $sale): RedirectResponse
    {
        abort_unless($sale->user_id === $request->user()->id, 403);

        $data = $request->validated();
        $data = $this->normalizeByType($data);

        $sale->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'sales.msg_updated']);

        return to_route('restaurant.sales.index');
    }

    /** Null out fields that don't belong to this sale type and cast days to ints. */
    private function normalizeByType(array $data): array
    {
        if (($data['type'] ?? 'periodic') === 'periodic') {
            $data['days']       = array_map('intval', $data['days'] ?? []);
            $data['starts_at']  = null;
            $data['ends_at']    = null;
        } else {
            $data['days']       = null;
            $data['start_time'] = null;
            $data['end_time']   = null;
        }

        return $data;
    }

    public function destroy(Request $request, Sale $sale): RedirectResponse
    {
        abort_unless($sale->user_id === $request->user()->id, 403);

        $sale->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'sales.msg_deleted']);

        return to_route('restaurant.sales.index');
    }
}
