<?php

namespace App\Http\Controllers\Restaurant;

use App\Enums\OnlinePaymentStatus;
use App\Enums\PaymentProvider;
use App\Http\Controllers\Controller;
use App\Models\FeeTier;
use App\Models\OnlinePayment;
use App\Models\User;
use App\Support\Payments\MercadoPagoGateway;
use App\Support\Payments\PaymentGateway;
use App\Support\Payments\PaymentGatewayException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The restaurant's financial profile: its account with Stripe and/or Mercado
 * Pago, one of which must be active before customers can pay its tables in the
 * app, and which of them new payments go to.
 */
class FinanceController extends Controller
{
    /** Pending OAuth state for linking Mercado Pago, in the owner's session. */
    private const MP_STATE = 'finance.mercadopago_state';

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly MercadoPagoGateway $mercadoPago,
    ) {}

    public function show(Request $request): Response
    {
        // Managers may look (read-only); the account shown is always the restaurant's.
        $user = $request->user()->restaurantAccount();

        // Until Stripe enables payments, look again on each visit (Stripe may finish
        // its review after the owner came back from onboarding).
        if ($user->stripe_account_id !== null && ! $user->stripe_charges_enabled) {
            $this->refreshStatus($user);
        }

        $payments = OnlinePayment::where('online_payments.restaurant_id', $user->id)
            ->whereIn('online_payments.status', [OnlinePaymentStatus::PAID->value, OnlinePaymentStatus::REFUNDED->value])
            ->join('restaurant_tables', 'restaurant_tables.id', '=', 'online_payments.restaurant_table_id')
            ->leftJoin('users as payers', 'payers.id', '=', 'online_payments.user_id')
            ->orderByDesc('online_payments.paid_at')
            ->limit(30)
            ->get([
                'online_payments.id',
                'online_payments.provider',
                'online_payments.paid_at',
                'online_payments.amount_cents',
                'online_payments.credited_cents',
                'online_payments.refunded_cents',
                'online_payments.discount_cents',
                'online_payments.coupon_by_platform',
                'online_payments.charge_cents',
                'online_payments.application_fee_cents',
                'online_payments.fee_refund_cents',
                'online_payments.owed_cents',
                'online_payments.owed_paid_at',
                'restaurant_tables.title as table_title',
                'payers.name as payer_name',
            ])
            ->map(fn (OnlinePayment $p) => [
                'id' => $p->id,
                'provider' => $p->provider->value,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'table' => $p->getAttribute('table_title'),
                'payer' => $p->getAttribute('payer_name'),
                'amount' => ($p->credited_cents ?? $p->amount_cents) / 100,
                'refunded' => $p->refunded_cents / 100,
                'discount' => $p->discount_cents / 100,
                'coupon_by_platform' => $p->coupon_by_platform,
                'charge' => $p->charge_cents / 100,
                // What the platform kept: a refund to the customer gives back its share of
                // the commission, and a sponsored restaurant's processing fee comes out of it too.
                'platform_fee' => max(0, ($p->charge_cents > 0
                    ? (int) round($p->application_fee_cents * ($p->charge_cents - $p->refunded_cents) / $p->charge_cents)
                    : 0) - ($p->fee_refund_cents ?? 0)) / 100,
                'fee_refund' => ($p->fee_refund_cents ?? 0) / 100,
                'owed' => $p->owed_cents / 100,
                'owed_paid' => $p->owed_paid_at !== null,
            ]);

        return Inertia::render('restaurant/finance', [
            'account' => [
                'connected' => $user->stripe_account_id !== null,
                'charges_enabled' => $user->stripe_charges_enabled,
                'details_submitted' => $user->stripe_details_submitted,
            ],
            'mercadoPago' => [
                // The platform's Mercado Pago app isn't set up (no credentials): nothing to link to.
                'available' => $this->mercadoPago->configured() || $user->mercadopago_user_id !== null,
                'connected' => $user->canReceiveWith(PaymentProvider::MERCADO_PAGO),
            ],
            // Where new payments go now (null: online payments are off).
            'provider' => $user->onlinePaymentProvider()?->value,
            'tier' => $user->feeTier(),
            'tierName' => FeeTier::where('key', $user->feeTier())->value('name') ?? $user->feeTier(),
            'feePercent' => $user->feeRateBps() / 100,
            'platformPaysStripeFees' => $user->platform_pays_stripe_fees,
            'payments' => $payments,
        ]);
    }

    /** Creates the Stripe account on first use and sends the owner to Stripe's onboarding. */
    public function connect(Request $request): SymfonyResponse
    {
        $user = $request->user();

        try {
            if ($user->stripe_account_id === null) {
                $accountId = $this->gateway->createAccount($user->email, (string) $user->name);

                // Of two clicks racing, the first account saved wins.
                User::whereKey($user->id)->whereNull('stripe_account_id')->update(['stripe_account_id' => $accountId]);
                $user->refresh();
            }

            // Linking an account is choosing it for the next payments.
            $user->forceFill(['payment_provider' => PaymentProvider::STRIPE])->save();

            return Inertia::location($this->onboardingUrl((string) $user->stripe_account_id));
        } catch (PaymentGatewayException $e) {
            Log::warning('Stripe onboarding failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            Inertia::flash('toast', ['type' => 'error', 'message' => 'finance.msg_stripe_error']);

            return to_route('restaurant.finance.show');
        }
    }

    /** Stripe sends the owner here when the onboarding link expired: a fresh one. */
    public function refresh(Request $request): RedirectResponse
    {
        $accountId = $request->user()->stripe_account_id;

        if ($accountId === null) {
            return to_route('restaurant.finance.show');
        }

        try {
            return redirect()->away($this->onboardingUrl($accountId));
        } catch (PaymentGatewayException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'finance.msg_stripe_error']);

            return to_route('restaurant.finance.show');
        }
    }

    /** Back from Stripe's onboarding: read the account's state. */
    public function back(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->stripe_account_id !== null && ! $this->refreshStatus($user)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'finance.msg_stripe_error']);

            return to_route('restaurant.finance.show');
        }

        Inertia::flash('toast', $user->stripe_charges_enabled
            ? ['type' => 'success', 'message' => 'finance.msg_enabled']
            : ['type' => 'error', 'message' => 'finance.msg_incomplete']);

        return to_route('restaurant.finance.show');
    }

    /** Sends the owner to Mercado Pago to link their account to the platform's app. */
    public function connectMercadoPago(Request $request): SymfonyResponse
    {
        if (! $this->mercadoPago->configured()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'finance.msg_mercadopago_error']);

            return to_route('restaurant.finance.show');
        }

        $state = Str::random(40);
        $request->session()->put(self::MP_STATE, $state);

        return Inertia::location($this->mercadoPago->authorizationUrl($state, route('restaurant.finance.mercadopago.return')));
    }

    /** Back from Mercado Pago's authorization: trade the code for the account's tokens. */
    public function mercadoPagoBack(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::MP_STATE);
        $state = $request->query('state');
        $code = $request->query('code');

        // Denied on Mercado Pago, or a link that didn't start here (another tab, a forged one).
        if (! is_string($expected) || ! is_string($state) || ! hash_equals($expected, $state)
            || ! is_string($code) || $code === '') {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'finance.msg_mercadopago_denied']);

            return to_route('restaurant.finance.show');
        }

        $user = $request->user();

        try {
            $tokens = $this->mercadoPago->exchangeCode($code, route('restaurant.finance.mercadopago.return'));
        } catch (PaymentGatewayException $e) {
            Log::warning('Mercado Pago linking failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            Inertia::flash('toast', ['type' => 'error', 'message' => 'finance.msg_mercadopago_error']);

            return to_route('restaurant.finance.show');
        }

        $user->forceFill([
            'mercadopago_user_id' => $tokens['user_id'],
            'mercadopago_access_token' => $tokens['access_token'],
            'mercadopago_refresh_token' => $tokens['refresh_token'],
            'mercadopago_token_expires_at' => $tokens['expires_at'],
            // Linking an account is choosing it for the next payments.
            'payment_provider' => PaymentProvider::MERCADO_PAGO,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'finance.msg_mercadopago_linked']);

        return to_route('restaurant.finance.show');
    }

    /**
     * Unlinks the Mercado Pago account. Not while a payment on it is still being
     * confirmed or settled: that needs the account's token.
     */
    public function disconnectMercadoPago(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->mercadopago_user_id === null) {
            return to_route('restaurant.finance.show');
        }

        $busy = OnlinePayment::where('restaurant_id', $user->id)
            ->where('provider', PaymentProvider::MERCADO_PAGO->value)
            ->where(fn ($q) => $q
                ->whereIn('status', OnlinePaymentStatus::inFlight())
                ->orWhere(fn ($q) => $q
                    ->whereIn('status', [OnlinePaymentStatus::PAID->value, OnlinePaymentStatus::REFUNDED->value])
                    ->whereNull('settled_at')))
            ->exists();

        if ($busy) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'finance.msg_mercadopago_busy']);

            return to_route('restaurant.finance.show');
        }

        $user->forceFill([
            'mercadopago_user_id' => null,
            'mercadopago_access_token' => null,
            'mercadopago_refresh_token' => null,
            'mercadopago_token_expires_at' => null,
            'payment_provider' => $user->payment_provider === PaymentProvider::MERCADO_PAGO ? null : $user->payment_provider,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'finance.msg_mercadopago_unlinked']);

        return to_route('restaurant.finance.show');
    }

    /** Which of its active accounts new payments go to. */
    public function chooseProvider(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'provider' => ['required', Rule::enum(PaymentProvider::class)],
        ]);
        $provider = PaymentProvider::from($data['provider']);

        if (! $user->canReceiveWith($provider)) {
            throw ValidationException::withMessages(['provider' => __('payments.provider_inactive')]);
        }

        $user->forceFill(['payment_provider' => $provider])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'finance.msg_provider_chosen']);

        return to_route('restaurant.finance.show');
    }

    /** Reads the account's state from Stripe; false when Stripe can't be reached. */
    private function refreshStatus(User $user): bool
    {
        try {
            $status = $this->gateway->accountStatus((string) $user->stripe_account_id);
        } catch (PaymentGatewayException) {
            return false;
        }

        $user->forceFill([
            'stripe_charges_enabled' => $status['charges_enabled'],
            'stripe_details_submitted' => $status['details_submitted'],
        ])->save();

        return true;
    }

    private function onboardingUrl(string $accountId): string
    {
        return $this->gateway->onboardingUrl(
            $accountId,
            route('restaurant.finance.refresh'),
            route('restaurant.finance.return'),
        );
    }
}
