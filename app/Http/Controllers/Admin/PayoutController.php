<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\OnlinePayment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the platform owes restaurants outside the payment provider: platform coupon
 * discounts beyond the payment's commission, and Mercado Pago's fee for sponsored
 * restaurants (neither Stripe Brazil nor Mercado Pago lets the platform transfer
 * them). The admin pays by Pix or bank transfer and marks them here.
 */
class PayoutController extends Controller
{
    public function index(): Response
    {
        $owed = OnlinePayment::query()
            ->where('owed_cents', '>', 0)
            ->whereNull('owed_paid_at')
            ->groupBy('restaurant_id')
            ->select('restaurant_id')
            ->selectRaw('SUM(owed_cents) as owed_cents, COUNT(*) as payments, MIN(paid_at) as since, MAX(id) as up_to_id')
            ->get();

        $restaurants = User::whereIn('id', $owed->pluck('restaurant_id'))
            ->get(['id', 'name', 'email', 'stripe_account_id', 'mercadopago_user_id'])
            ->keyBy('id');

        return Inertia::render('admin/payouts', [
            'payouts' => $owed
                ->map(function (OnlinePayment $row) use ($restaurants) {
                    $restaurant = $restaurants->get($row->restaurant_id);

                    return [
                        'restaurant_id' => $row->restaurant_id,
                        'name' => $restaurant?->name,
                        'email' => $restaurant?->email,
                        'payments_connected' => $restaurant !== null
                            && ($restaurant->stripe_account_id !== null || $restaurant->mercadopago_user_id !== null),
                        'amount' => ((int) $row->getAttribute('owed_cents')) / 100,
                        'payments' => (int) $row->getAttribute('payments'),
                        'since' => $row->getAttribute('since'),
                        'up_to_id' => (int) $row->getAttribute('up_to_id'),
                    ];
                })
                ->sortByDesc('amount')
                ->values(),
        ]);
    }

    /** The admin paid a restaurant what was listed: mark exactly those payments as paid. */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'restaurant_id' => ['required', 'integer'],
            // Only what the admin saw: a payment confirmed after the page loaded stays owed.
            'up_to_id' => ['required', 'integer'],
        ]);

        abort_unless(
            User::whereKey($validated['restaurant_id'])->where('account_type', AccountType::RESTAURANT->value)->exists(),
            404,
        );

        DB::table('online_payments')
            ->where('restaurant_id', $validated['restaurant_id'])
            ->where('id', '<=', $validated['up_to_id'])
            ->where('owed_cents', '>', 0)
            ->whereNull('owed_paid_at')
            ->update(['owed_paid_at' => now(), 'updated_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'admin_payouts.msg_paid']);

        return to_route('admin.payouts.index');
    }
}
