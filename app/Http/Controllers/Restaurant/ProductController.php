<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\RestaurantQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $products = Product::where('user_id', $user->effectiveRestaurantId())
            ->with('category:id,name')
            ->orderBy('name')
            ->get();

        return Inertia::render('restaurant/products', [
            'products'  => $products,
            'canManage' => $user->account_type->value === 'restaurant',
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('restaurant/products/create', [
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'queues'     => RestaurantQueue::where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $data = $request->safe()->except('picture');
        $data['user_id'] = $request->user()->id;

        if ($request->hasFile('picture')) {
            $data['picture'] = $request->file('picture')->store('products', 'public');
        }

        Product::create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'products.msg_created']);

        return to_route('restaurant.products.index');
    }

    public function edit(Request $request, Product $product): Response
    {
        abort_unless($product->user_id === $request->user()->id, 403);

        return Inertia::render('restaurant/products/edit', [
            'product'    => $product,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'queues'     => RestaurantQueue::where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        abort_unless($product->user_id === $request->user()->id, 403);

        $data = $request->safe()->except('picture');

        if ($request->hasFile('picture')) {
            if ($product->picture) {
                Storage::disk('public')->delete($product->picture);
            }
            $data['picture'] = $request->file('picture')->store('products', 'public');
        }

        $product->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'products.msg_updated']);

        return to_route('restaurant.products.index');
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->user_id === $request->user()->id, 403);

        $product->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'products.msg_deleted']);

        return to_route('restaurant.products.index');
    }
}
