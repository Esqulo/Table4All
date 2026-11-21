<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\FeeTier;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The commercial plans (default, founder…): the platform's commission on a restaurant's
 * online payments. A new percentage applies to payments started afterwards; each payment
 * keeps the rate it was charged with.
 */
class FeeTierController extends Controller
{
    public function index(): Response
    {
        $restaurants = User::where('account_type', AccountType::RESTAURANT->value)
            ->selectRaw('COALESCE(fee_tier, ?) as tier, COUNT(*) as total', [FeeTier::defaultKey()])
            ->groupBy('tier')
            ->pluck('total', 'tier');

        $tiers = FeeTier::orderBy('fee_percent')->orderBy('name')->get();
        $known = $tiers->pluck('key')->all();
        // Restaurants whose tier was removed count under the default one, as they're charged.
        $orphans = $restaurants->except($known)->sum();

        return Inertia::render('admin/fee-tiers', [
            'tiers' => $tiers->map(fn (FeeTier $tier) => [
                'id' => $tier->id,
                'key' => $tier->key,
                'name' => $tier->name,
                'fee_percent' => (float) $tier->fee_percent,
                'is_default' => $tier->isDefault(),
                'restaurants' => (int) ($restaurants[$tier->key] ?? 0) + ($tier->isDefault() ? (int) $orphans : 0),
            ])->values(),
            'maxPercent' => FeeTier::MAX_PERCENT,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());

        FeeTier::create([
            'key' => FeeTier::keyFor($data['name']),
            'name' => $data['name'],
            'fee_percent' => $data['fee_percent'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'admin_tiers.msg_created']);

        return back();
    }

    public function update(Request $request, FeeTier $feeTier): RedirectResponse
    {
        $data = $request->validate($this->rules($feeTier));

        $feeTier->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'admin_tiers.msg_updated']);

        return back();
    }

    /** Only an unused tier goes, and never the default one (restaurants without a tier get it). */
    public function destroy(FeeTier $feeTier): RedirectResponse
    {
        if ($feeTier->isDefault()) {
            return back()->withErrors(['tier' => __('admin.error_tier_default_locked')]);
        }

        if (User::where('account_type', AccountType::RESTAURANT->value)->where('fee_tier', $feeTier->key)->exists()) {
            return back()->withErrors(['tier' => __('admin.error_tier_in_use')]);
        }

        $feeTier->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'admin_tiers.msg_deleted']);

        return back();
    }

    /** @return array<string, list<mixed>> */
    private function rules(?FeeTier $tier = null): array
    {
        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('fee_tiers', 'name')->ignore($tier?->id)],
            'fee_percent' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.FeeTier::MAX_PERCENT],
        ];
    }
}
