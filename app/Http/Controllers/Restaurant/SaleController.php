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
            ->orderBy('start_time')
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
        $data['days'] = array_map('intval', $data['days']);

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
        $data['days'] = array_map('intval', $data['days']);

        $sale->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'sales.msg_updated']);

        return to_route('restaurant.sales.index');
    }

    public function destroy(Request $request, Sale $sale): RedirectResponse
    {
        abort_unless($sale->user_id === $request->user()->id, 403);

        $sale->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'sales.msg_deleted']);

        return to_route('restaurant.sales.index');
    }
}
