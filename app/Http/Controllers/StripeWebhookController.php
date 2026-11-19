<?php

namespace App\Http\Controllers;

use App\Models\OnlinePayment;
use App\Models\User;
use App\Support\Payments\PaymentGateway;
use App\Support\Payments\TableCheckout;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Stripe's notifications. Only the event's id is trusted from the payload: the
 * payment is then read back from Stripe (TableCheckout::sync), so a replayed
 * or out-of-order event can't change anything by itself.
 */
class StripeWebhookController extends Controller
{
    private const CHECKOUT_EVENTS = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'checkout.session.expired',
    ];

    public function __invoke(Request $request, PaymentGateway $gateway, TableCheckout $checkout): Response
    {
        $event = $gateway->webhookEvent($request->getContent(), (string) $request->header('Stripe-Signature'));

        if ($event === null) {
            return response('Invalid signature', 400);
        }

        $objectId = (string) ($event['object']['id'] ?? '');

        if (in_array($event['type'], self::CHECKOUT_EVENTS, true)) {
            $payment = OnlinePayment::where('checkout_session_id', $objectId)->first();

            if ($payment !== null) {
                $checkout->sync($payment);
            }
        } elseif (($event['type'] === 'account.updated' || str_starts_with($event['type'], 'v2.core.account'))
            && str_starts_with($objectId, 'acct_')) {
            // The restaurant's account changed (onboarding finished, capability lost):
            // read its state back from Stripe.
            $restaurant = User::where('stripe_account_id', $objectId)->first();

            if ($restaurant !== null) {
                $status = $gateway->accountStatus($objectId);
                $restaurant->forceFill([
                    'stripe_charges_enabled' => $status['charges_enabled'],
                    'stripe_details_submitted' => $status['details_submitted'],
                ])->save();
            }
        }

        return response('OK', 200);
    }
}
