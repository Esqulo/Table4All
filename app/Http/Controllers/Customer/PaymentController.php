<?php

namespace App\Http\Controllers\Customer;

use App\Enums\OnlinePaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\OnlinePayment;
use App\Models\RestaurantTable;
use App\Support\Payments\CheckoutRefused;
use App\Support\Payments\PaymentGatewayException;
use App\Support\Payments\TableCheckout;
use App\Support\TableMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A customer pays (part of) their table's bill in the app, on the provider's hosted
 * checkout, optionally with a discount coupon.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly TableCheckout $checkout) {}

    public function store(Request $request, string $code): Response
    {
        $table = $this->tableFor($request, $code);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            'coupon' => ['nullable', 'string', 'max:40'],
            'request_key' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $payment = $this->checkout->start(
                $request->user(),
                $table,
                (int) round((float) $validated['amount'] * 100),
                $validated['coupon'] ?? null,
                $validated['request_key'] ?? null,
                app()->getLocale(),
            );
        } catch (CheckoutRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->translated()]);
        }

        if (in_array($payment->status, [OnlinePaymentStatus::PAID, OnlinePaymentStatus::REFUNDED], true)) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'payments.msg_paid']);

            return redirect()->route('customer.table', $code);
        }

        // The provider's page is outside the app: a full-page visit, not an Inertia one.
        return Inertia::location((string) $payment->checkout_url);
    }

    /** Where the provider sends the customer back, after paying or giving up. */
    public function back(Request $request, OnlinePayment $payment): RedirectResponse
    {
        abort_unless($payment->user_id === $request->user()->id, 403);

        try {
            $payment = $request->query('result') === 'cancel'
                ? $this->checkout->cancel($payment)
                : $this->checkout->sync($payment);
        } catch (PaymentGatewayException) {
            // Provider unreachable right now: the webhook or the reconcile command will catch up.
        }

        $message = match ($payment->status) {
            // The bill had been (partly) paid meanwhile: the difference went back to the card / Pix.
            OnlinePaymentStatus::PAID => $payment->refunded_cents > 0
                ? ['type' => 'success', 'message' => 'payments.msg_paid_partly_refunded']
                : ['type' => 'success', 'message' => 'payments.msg_paid'],
            OnlinePaymentStatus::REFUNDED => ['type' => 'error', 'message' => 'payments.msg_refunded'],
            OnlinePaymentStatus::PROCESSING => ['type' => 'success', 'message' => 'payments.msg_processing'],
            OnlinePaymentStatus::CANCELED => ['type' => 'error', 'message' => 'payments.msg_canceled'],
            OnlinePaymentStatus::PENDING => ['type' => 'error', 'message' => 'payments.msg_pending'],
        };
        Inertia::flash('toast', $message);

        return $this->backToTable($payment);
    }

    /** The customer drops a checkout they left open, freeing the amount it held. */
    public function cancel(Request $request, OnlinePayment $payment): RedirectResponse
    {
        abort_unless($payment->user_id === $request->user()->id, 403);

        try {
            $payment = $this->checkout->cancel($payment);
        } catch (PaymentGatewayException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'payments.msg_gateway_error']);

            return $this->backToTable($payment);
        }

        if ($payment->status === OnlinePaymentStatus::PAID) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'payments.msg_paid']);
        }

        return $this->backToTable($payment);
    }

    private function tableFor(Request $request, string $code): RestaurantTable
    {
        $table = RestaurantTable::where('access_code', $code)
            ->whereNull('closed_at')
            ->whereNull('deleted_at')
            ->firstOrFail(['id', 'user_id', 'title', 'responsible_user_id']);

        abort_unless(TableMembership::isMember($table, $request->user()->id), 403);

        return $table;
    }

    private function backToTable(OnlinePayment $payment): RedirectResponse
    {
        $code = RestaurantTable::whereKey($payment->restaurant_table_id)
            ->whereNull('closed_at')
            ->value('access_code');

        // Back on the bill tab, where the payment now shows.
        return $code !== null
            ? redirect()->route('customer.table', ['code' => $code, 'aba' => 'conta'])
            : redirect()->route('customer.join');
    }
}
