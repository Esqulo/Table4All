<?php

namespace App\Http\Controllers\Restaurant;

use App\Enums\Permission;
use App\Enums\StaffFeature;
use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\RestaurantQueue;
use App\Support\ImageOptimizer;
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
            'products' => $products,
            'canManage' => $user->access(StaffFeature::PRODUCTS) === Permission::WRITE,
            'canToggleSoldOut' => Product::canToggleSoldOut($user),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('restaurant/products/create', [
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'queues' => RestaurantQueue::where('user_id', $request->user()->effectiveRestaurantId())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $data = $request->safe()->except('picture');
        $data['user_id'] = $request->user()->effectiveRestaurantId();

        if ($request->hasFile('picture')) {
            $data['picture'] = ImageOptimizer::storeUpload($request->file('picture'), 'products');
        }

        Product::create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'products.msg_created']);

        return to_route('restaurant.products.index');
    }

    public function edit(Request $request, Product $product): Response
    {
        abort_unless($product->user_id === $request->user()->effectiveRestaurantId(), 403);

        return Inertia::render('restaurant/products/edit', [
            'product' => $product,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'queues' => RestaurantQueue::where('user_id', $request->user()->effectiveRestaurantId())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        abort_unless($product->user_id === $request->user()->effectiveRestaurantId(), 403);

        $data = $request->safe()->except('picture');

        if ($request->hasFile('picture')) {
            if ($product->picture) {
                Storage::disk('public')->delete($product->picture);
            }
            $data['picture'] = ImageOptimizer::storeUpload($request->file('picture'), 'products');
        }

        $product->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'products.msg_updated']);

        return to_route('restaurant.products.index');
    }

    /** Swap only the picture, straight from the product list, without resending the other fields. */
    public function updatePicture(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->user_id === $request->user()->effectiveRestaurantId(), 403);

        $request->validate([
            'picture' => ['required', 'image', 'max:2048'],
        ]);

        $old = $product->picture;
        $product->update([
            'picture' => ImageOptimizer::storeUpload($request->file('picture'), 'products'),
        ]);

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'products.msg_picture_updated']);

        return back();
    }

    /**
     * Marks the product sold out, or available again. Setting the state it already has
     * changes nothing, so a retried or double-tapped request is harmless and keeps the
     * time it first sold out.
     */
    public function soldOut(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->user_id === $request->user()->effectiveRestaurantId(), 403);

        /** @var array{sold_out: bool} $data */
        $data = $request->validate([
            'sold_out' => ['required', 'boolean'],
        ]);

        $query = Product::whereKey($product->id);

        if ($data['sold_out']) {
            $query->whereNull('sold_out_at')->update(['sold_out_at' => now()]);
        } else {
            $query->whereNotNull('sold_out_at')->update(['sold_out_at' => null]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $data['sold_out'] ? 'products.msg_sold_out' : 'products.msg_available',
        ]);

        return back();
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->user_id === $request->user()->effectiveRestaurantId(), 403);

        $product->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'products.msg_deleted']);

        return to_route('restaurant.products.index');
    }
}
