<?php

namespace App\Http\Controllers;

use App\Enums\PaymentProvider;
use App\Models\OnlinePayment;
use App\Models\User;
use App\Support\Payments\MercadoPagoGateway;
use App\Support\Payments\PaymentGatewayException;
use App\Support\Payments\TableCheckout;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Mercado Pago's notifications, signed with the platform app's webhook secret.
 * Only the payment's id is taken from the notification: the payment is read
 * back from Mercado Pago with the restaurant's token and then synced
 * (TableCheckout::sync), so a replayed or out-of-order notification can't
 * change anything by itself. The reconcile command catches whatever is missed.
 */
class MercadoPagoWebhookController extends Controller
{
    public function __invoke(Request $request, MercadoPagoGateway $gateway, TableCheckout $checkout): Response
    {
        // "data.id" in the query string reaches PHP as "data_id".
        $dataId = (string) ($request->query('data_id') ?? $request->input('data.id') ?? '');

        if (! $gateway->validSignature($request->header('x-signature'), $request->header('x-request-id'), $dataId)) {
            return response('Invalid signature', 400);
        }

        $type = (string) ($request->input('type') ?? $request->query('type') ?? '');
        $accountId = (string) ($request->input('user_id') ?? '');

        if ($accountId === '') {
            return response('OK', 200);
        }

        if ($type === 'payment' && $dataId !== '') {
            try {
                $reference = $gateway->payment($accountId, $dataId)['external_reference'] ?? null;

                $payment = is_string($reference) && $reference !== ''
                    ? OnlinePayment::where('reference', $reference)
                        ->where('provider', PaymentProvider::MERCADO_PAGO->value)
                        ->where('account_id', $accountId)
                        ->first()
                    : null;

                if ($payment !== null) {
                    $checkout->sync($payment);
                }
            } catch (PaymentGatewayException $e) {
                // Left to the reconcile command.
                Log::warning('Mercado Pago notification not processed', ['payment' => $dataId, 'error' => $e->getMessage()]);
            }
        } elseif ($type === 'mp-connect' && $request->input('action') === 'application.deauthorized') {
            // The owner unlinked the platform on Mercado Pago's side: its tokens no longer work.
            User::where('mercadopago_user_id', $accountId)->each(function (User $restaurant): void {
                $restaurant->forceFill([
                    'mercadopago_user_id' => null,
                    'mercadopago_access_token' => null,
                    'mercadopago_refresh_token' => null,
                    'mercadopago_token_expires_at' => null,
                    'payment_provider' => $restaurant->payment_provider === PaymentProvider::MERCADO_PAGO ? null : $restaurant->payment_provider,
                ])->save();
            });
        }

        return response('OK', 200);
    }
}
