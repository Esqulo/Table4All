<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MenuController extends Controller
{
    public function index(Request $request): Response
    {
        $menus = Menu::where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get()
            ->map(fn (Menu $menu) => [
                ...$menu->toArray(),
                'url' => route('menu.show', $menu),
            ]);

        return Inertia::render('restaurant/menus', [
            'menus' => $menus,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('restaurant/menus/create');
    }

    public function printMenu(Request $request, Menu $menu): Response
    {
        abort_unless($menu->user_id === $request->user()->id, 403);

        $menu->load(['user' => fn ($q) => $q->select('id', 'name')]);

        $products = Product::where('user_id', $menu->user_id)
            ->with('category:id,name')
            ->orderBy('name')
            ->get(['id', 'category_id', 'name', 'description', 'picture', 'price', 'price_type']);

        $usedCategoryIds = $products->pluck('category_id')->filter()->unique();
        $categories = Category::whereIn('id', $usedCategoryIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('restaurant/menus/print', [
            'menu'       => $menu,
            'categories' => $categories,
            'products'   => $products,
        ]);
    }

    public function show(Menu $menu): Response
    {
        $menu->load(['user' => fn ($q) => $q->select('id', 'name', 'avatar')]);

        $products = Product::where('user_id', $menu->user_id)
            ->with('category:id,name')
            ->orderBy('name')
            ->get(['id', 'category_id', 'name', 'description', 'picture', 'price', 'price_type']);

        $usedCategoryIds = $products->pluck('category_id')->filter()->unique();
        $categories = Category::whereIn('id', $usedCategoryIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('menu/show', [
            'menu'       => $menu,
            'categories' => $categories,
            'products'   => $products,
            'url'        => route('menu.show', $menu),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        Menu::create([
            'user_id' => $request->user()->id,
            'name'    => $validated['name'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'menus.msg_created']);

        return to_route('restaurant.menus.index');
    }
}
