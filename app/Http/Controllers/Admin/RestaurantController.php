<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Mail\RestaurantWelcomeMail;
use App\Models\FeeTier;
use App\Models\User;
use App\Support\Admin\PlatformStats;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The restaurants on the platform: creating their accounts, a full report on each, and
 * the platform's terms with them (plan, an own commission, who pays Stripe's fee).
 */
class RestaurantController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $tiers = $this->tiers();
        $tier = (string) $request->query('tier', '');
        if ($tier !== '' && ! $tiers->contains('key', $tier)) {
            $tier = '';
        }
        $defaultKey = FeeTier::defaultKey();
        $knownKeys = $tiers->pluck('key')->all();

        $restaurants = User::where('account_type', AccountType::RESTAURANT->value)
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('search_name', 'like', '%'.User::searchable($search).'%')
                ->orWhere('email', 'ilike', '%'.$search.'%')))
            ->when($tier !== '', fn ($q) => $tier === $defaultKey
                // Restaurants without a tier, or with one that was removed, are on the default.
                ? $q->where(fn ($q) => $q->whereNull('fee_tier')->orWhere('fee_tier', $defaultKey)->orWhereNotIn('fee_tier', $knownKeys))
                : $q->where('fee_tier', $tier))
            ->orderBy('name')
            ->select(['id', 'name', 'email', 'fee_tier', 'fee_percent_override', 'platform_pays_stripe_fees', 'stripe_account_id', 'stripe_charges_enabled', 'mercadopago_user_id', 'last_seen_at', 'created_at', 'deactivated_at'])
            ->paginate(50)
            ->withQueryString()
            ->through(fn (User $restaurant) => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'email' => $restaurant->email,
                'fee_tier' => in_array($restaurant->fee_tier, $knownKeys, true) ? $restaurant->fee_tier : $defaultKey,
                'fee_percent_override' => $restaurant->fee_percent_override !== null ? (float) $restaurant->fee_percent_override : null,
                'platform_pays_stripe_fees' => $restaurant->platform_pays_stripe_fees,
                'stripe_connected' => $restaurant->stripe_account_id !== null,
                'stripe_charges_enabled' => $restaurant->stripe_charges_enabled,
                'mercadopago_connected' => $restaurant->mercadopago_user_id !== null,
                'last_seen_at' => $restaurant->last_seen_at?->toIso8601String(),
                'created_at' => $restaurant->created_at?->toIso8601String(),
                'deactivated' => $restaurant->deactivated_at !== null,
            ]);

        return Inertia::render('admin/restaurants', [
            'restaurants' => $restaurants,
            'tiers' => $tiers,
            'defaultTier' => $defaultKey,
            'maxPercent' => FeeTier::MAX_PERCENT,
            'search' => $search,
            'tier' => $tier,
        ]);
    }

    /**
     * Creates a restaurant's account. The admin vouches for the address, so it starts
     * verified; the owner gets an e-mail with a link to choose the password.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'phone' => ['nullable', 'string', 'max:30'],
            ...$this->termsRules(),
        ]);

        $restaurant = DB::transaction(function () use ($data) {
            $restaurant = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'account_type' => AccountType::RESTAURANT,
                'password' => Str::password(40),
            ]);
            $restaurant->forceFill([
                'email_verified_at' => now(),
                ...$this->terms($data),
            ])->save();

            return $restaurant;
        });

        /** @var PasswordBroker $broker */
        $broker = Password::broker();
        Mail::to($restaurant->email)->send(new RestaurantWelcomeMail($restaurant, $broker->createToken($restaurant)));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'admin_restaurants.msg_created']);

        return redirect()->route('admin.restaurants.show', $restaurant);
    }

    /** Everything about one restaurant: its account, how it is doing, and its terms. */
    public function show(User $restaurant, PlatformStats $stats): Response
    {
        abort_unless($restaurant->account_type === AccountType::RESTAURANT, 404);

        $tiers = $this->tiers();

        return Inertia::render('admin/restaurant', [
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'email' => $restaurant->email,
                'phone' => $restaurant->phone,
                'handle' => $restaurant->handle,
                'address' => $restaurant->address,
                'logo_url' => $restaurant->logo_url,
                'profile_public' => $restaurant->profile_public,
                'email_verified' => $restaurant->email_verified_at !== null,
                'two_factor' => $restaurant->two_factor_confirmed_at !== null,
                'created_at' => $restaurant->created_at?->toIso8601String(),
                'last_login_at' => $restaurant->last_login_at?->toIso8601String(),
                'last_seen_at' => $restaurant->last_seen_at?->toIso8601String(),
                'stripe_connected' => $restaurant->stripe_account_id !== null,
                'stripe_charges_enabled' => $restaurant->stripe_charges_enabled,
                'mercadopago_connected' => $restaurant->mercadopago_user_id !== null,
                'stripe_details_submitted' => $restaurant->stripe_details_submitted,
                'fee_tier' => $restaurant->feeTier(),
                'fee_percent_override' => $restaurant->fee_percent_override !== null ? (float) $restaurant->fee_percent_override : null,
                'effective_fee_percent' => $restaurant->feeRateBps() / 100,
                'platform_pays_stripe_fees' => $restaurant->platform_pays_stripe_fees,
                ...UserController::deactivation($restaurant),
            ],
            'tiers' => $tiers,
            'maxPercent' => FeeTier::MAX_PERCENT,
            'days' => PlatformStats::DAYS,
            ...$stats->restaurant($restaurant->id),
        ]);
    }

    public function update(Request $request, User $restaurant): RedirectResponse
    {
        abort_unless($restaurant->account_type === AccountType::RESTAURANT, 404);

        $data = $request->validate($this->termsRules());

        // Applies to payments started from now on; each payment keeps the terms it began with.
        $restaurant->forceFill($this->terms($data, $request->has('fee_percent_override')))->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'admin_restaurants.msg_updated']);

        return back();
    }

    /** @return array<string, list<mixed>> */
    private function termsRules(): array
    {
        return [
            'fee_tier' => ['required', 'string', Rule::exists('fee_tiers', 'key')],
            'platform_pays_stripe_fees' => ['required', 'boolean'],
            // Absent: unchanged. Null: back to the tier's commission.
            'fee_percent_override' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.FeeTier::MAX_PERCENT],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function terms(array $data, bool $withOverride = true): array
    {
        $terms = [
            'fee_tier' => $data['fee_tier'],
            'platform_pays_stripe_fees' => (bool) $data['platform_pays_stripe_fees'],
        ];

        if ($withOverride) {
            $override = $data['fee_percent_override'] ?? null;
            $terms['fee_percent_override'] = $override === null || $override === '' ? null : $override;
        }

        return $terms;
    }

    /** @return Collection<int, array{key: string, name: string, fee_percent: float}> */
    private function tiers(): Collection
    {
        return FeeTier::orderBy('fee_percent')->orderBy('name')->get(['key', 'name', 'fee_percent'])
            ->map(fn (FeeTier $tier) => [
                'key' => $tier->key,
                'name' => $tier->name,
                'fee_percent' => (float) $tier->fee_percent,
            ])
            ->values();
    }
}
