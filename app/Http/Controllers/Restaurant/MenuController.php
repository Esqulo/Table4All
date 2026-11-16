<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Menu;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MenuController extends Controller
{
    /** Keep in sync with resources/js/components/menu-print/config.ts. */
    public const TEMPLATES = ['classic', 'grid', 'elegant', 'compact', 'photo', 'chalkboard', 'modern', 'minimal'];

    public const ITEM_STYLES = ['list', 'leaders', 'elegant', 'photo', 'grid'];

    public const FONTS = [
        'georgia', 'arial', 'playfair', 'cormorant', 'lora', 'merriweather', 'ebgaramond', 'robotoslab',
        'abril', 'lato', 'montserrat', 'poppins', 'raleway', 'nunito', 'josefin', 'oswald', 'bebas',
        'pacifico', 'dancing', 'caveat', 'patrick', 'amatic',
    ];

    public const PAPERS = ['A3', 'A4', 'A5', 'Letter', 'Legal'];

    public function index(Request $request): Response
    {
        $menus = Menu::where('user_id', $request->user()->effectiveRestaurantId())
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
        abort_unless($menu->user_id === $request->user()->effectiveRestaurantId(), 403);

        $menu->load(['user' => fn ($q) => $q->select('id', 'name')]);

        [$categories, $products] = $this->catalog($menu);

        return Inertia::render('restaurant/menus/print', [
            'menu' => $menu,
            'layout' => $menu->layout,
            'categories' => $categories,
            'products' => $products,
        ]);
    }

    public function updateLayout(Request $request, Menu $menu): RedirectResponse
    {
        abort_unless($menu->user_id === $request->user()->effectiveRestaurantId(), 403);

        $validated = $request->validate([
            'layout' => ['required', 'array'],
            'layout.template' => ['required', Rule::in(self::TEMPLATES)],
            'layout.item_style' => ['required', Rule::in(self::ITEM_STYLES)],
            'layout.paper' => ['required', Rule::in(self::PAPERS)],
            'layout.landscape' => ['required', 'boolean'],
            'layout.columns' => ['required', 'integer', 'between:1,3'],
            'layout.heading_font' => ['required', Rule::in(self::FONTS)],
            'layout.body_font' => ['required', Rule::in(self::FONTS)],
            'layout.accent' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'layout.scale' => ['required', 'numeric', 'between:0.7,1.4'],
            'layout.grouped' => ['required', 'boolean'],
            'layout.show_descriptions' => ['required', 'boolean'],
            'layout.show_images' => ['required', 'boolean'],
            'layout.section_page_break' => ['required', 'boolean'],
            'layout.show_header' => ['required', 'boolean'],
            'layout.show_page_numbers' => ['required', 'boolean'],
            'layout.title' => ['nullable', 'string', 'max:120'],
            'layout.subtitle' => ['nullable', 'string', 'max:160'],
            'layout.footer' => ['nullable', 'string', 'max:240'],
            'layout.sections' => ['present', 'array', 'max:500'],
            'layout.sections.*.key' => ['required', 'integer'],
            'layout.sections.*.title' => ['nullable', 'string', 'max:120'],
            'layout.sections.*.style' => ['nullable', Rule::in(self::ITEM_STYLES)],
            'layout.sections.*.hidden' => ['required', 'boolean'],
            'layout.sections.*.products' => ['present', 'array', 'max:2000'],
            'layout.sections.*.products.*' => ['integer'],
            'layout.flat_order' => ['present', 'array', 'max:2000'],
            'layout.flat_order.*' => ['integer'],
            'layout.hidden_products' => ['present', 'array', 'max:2000'],
            'layout.hidden_products.*' => ['integer'],
        ]);

        $layout = $validated['layout'];
        $layout['scale'] = round((float) $layout['scale'], 2);
        $layout['columns'] = (int) $layout['columns'];

        $menu->update(['layout' => $layout]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'menus.msg_layout_saved']);

        return back();
    }

    public function show(Menu $menu): Response
    {
        $menu->load(['user' => fn ($q) => $q->select('id', 'name', 'avatar')]);

        [$categories, $products] = $this->catalog($menu);

        return Inertia::render('menu/show', [
            'menu' => $menu,
            'categories' => $categories,
            'products' => $products,
            'url' => route('menu.show', $menu),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        Menu::create([
            'user_id' => $request->user()->effectiveRestaurantId(),
            'name' => $validated['name'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'menus.msg_created']);

        return to_route('restaurant.menus.index');
    }

    /**
     * The restaurant's live products and the categories they use.
     *
     * @return array{0: Collection<int, Category>, 1: Collection<int, Product>}
     */
    private function catalog(Menu $menu): array
    {
        $products = Product::where('user_id', $menu->user_id)
            ->orderBy('name')
            ->get(['id', 'category_id', 'name', 'description', 'picture', 'price', 'price_type']);

        $categories = Category::whereIn('id', $products->pluck('category_id')->filter()->unique())
            ->orderBy('name')
            ->get(['id', 'name']);

        return [$categories, $products];
    }
}
