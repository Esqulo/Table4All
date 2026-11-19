<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The platform's terms with each restaurant: fee tier and who pays Stripe's fee. */
class RestaurantController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $restaurants = User::where('account_type', AccountType::RESTAURANT->value)
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('search_name', 'like', '%'.User::searchable($search).'%')
                ->orWhere('email', 'ilike', '%'.$search.'%')))
            ->orderBy('name')
            ->select(['id', 'name', 'email', 'fee_tier', 'platform_pays_stripe_fees', 'stripe_account_id', 'stripe_charges_enabled'])
            ->paginate(50)
            ->withQueryString()
            ->through(fn (User $restaurant) => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'email' => $restaurant->email,
                'fee_tier' => $restaurant->feeTier(),
                'platform_pays_stripe_fees' => $restaurant->platform_pays_stripe_fees,
                'stripe_connected' => $restaurant->stripe_account_id !== null,
                'stripe_charges_enabled' => $restaurant->stripe_charges_enabled,
            ]);

        /** @var array<string, array{fee_percent: float|int}> $tiers */
        $tiers = config('payments.tiers');

        return Inertia::render('admin/restaurants', [
            'restaurants' => $restaurants,
            'tiers' => collect($tiers)->map(fn (array $tier, string $key) => [
                'key' => $key,
                'fee_percent' => (float) $tier['fee_percent'],
            ])->values(),
            'search' => $search,
        ]);
    }

    public function update(Request $request, User $restaurant): RedirectResponse
    {
        abort_unless($restaurant->account_type === AccountType::RESTAURANT, 404);

        /** @var array<string, mixed> $tiers */
        $tiers = config('payments.tiers');

        $validated = $request->validate([
            'fee_tier' => ['required', 'string', Rule::in(array_keys($tiers))],
            'platform_pays_stripe_fees' => ['required', 'boolean'],
        ]);

        // Applies to payments started from now on; each payment keeps the terms it began with.
        $restaurant->forceFill([
            'fee_tier' => $validated['fee_tier'],
            'platform_pays_stripe_fees' => (bool) $validated['platform_pays_stripe_fees'],
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'admin_restaurants.msg_updated']);

        return back();
    }
}
