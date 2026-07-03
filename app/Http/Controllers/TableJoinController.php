<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\RestaurantTable;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

class TableJoinController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('table/join');
    }

    public function show(string $code): Response|RedirectResponse
    {
        $table = RestaurantTable::where('access_code', $code)
            ->whereNull('closed_at')
            ->whereNull('deleted_at')
            ->with('user:id,name,avatar')
            ->first();

        if (! $table) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'join_table.invalid_code']);

            return redirect()->route('table.join');
        }

        $products = Product::where('user_id', $table->user_id)
            ->with('category:id,name')
            ->orderBy('name')
            ->get(['id', 'category_id', 'name', 'description', 'picture', 'price', 'price_type'])
            ->map(fn ($p) => [
                'id'          => $p->id,
                'category_id' => $p->category_id,
                'category'    => $p->category,
                'name'        => $p->name,
                'description' => $p->description,
                'price'       => (float) $p->price,
                'price_type'  => $p->price_type,
                'picture_url' => $p->picture ? Storage::disk('public')->url($p->picture) : null,
            ]);

        $categories = $products
            ->pluck('category')
            ->filter()
            ->unique('id')
            ->values();

        return Inertia::render('table/show', [
            'table'      => ['id' => $table->id, 'title' => $table->title],
            'restaurant' => [
                'name'       => $table->user->name,
                'avatar_url' => $table->user->avatar_url,
            ],
            'categories' => $categories,
            'products'   => $products,
        ]);
    }
}
