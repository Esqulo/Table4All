<?php

namespace App\Console\Commands;

use App\Enums\OnlinePaymentStatus;
use App\Models\OnlinePayment;
use App\Support\Payments\PaymentGatewayException;
use App\Support\Payments\TableCheckout;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The safety net for online payments. The customer's return from the provider and
 * the webhook normally settle everything; this catches what they missed:
 * a customer who closed the tab after paying, a webhook that never arrived
 * (or isn't configured, as in development), a Pix confirmed later, an
 * abandoned checkout still holding its amount, a refund that failed, a sponsored
 * restaurant's processing fee not known yet. Works for Stripe and Mercado Pago alike.
 * Scheduled every minute (routes/console.php).
 */
#[Signature('payments:reconcile')]
#[Description('Bring open online payments up to date with their provider and retry pending refunds')]
final class ReconcilePayments extends Command
{
    /** Leave fresh checkouts to the return URL and the webhook. */
    private const GRACE_SECONDS = 60;

    private const BATCH = 100;

    public function handle(TableCheckout $checkout): int
    {
        $synced = 0;
        $failed = 0;

        $open = OnlinePayment::whereIn('status', OnlinePaymentStatus::inFlight())
            ->where('updated_at', '<', now()->subSeconds(self::GRACE_SECONDS))
            ->orderBy('updated_at')
            ->limit(self::BATCH)
            ->get();

        // Confirmed, but a refund to the customer or a commission refund to the restaurant is still owed.
        $unsettled = OnlinePayment::whereIn('status', [OnlinePaymentStatus::PAID->value, OnlinePaymentStatus::REFUNDED->value])
            ->whereNull('settled_at')
            ->orderBy('paid_at')
            ->limit(self::BATCH)
            ->get();

        foreach ($open->concat($unsettled) as $payment) {
            try {
                $checkout->sync($payment);
                // Touch it so the next run starts with others (a Pix may wait for hours).
                if (in_array($payment->status, [OnlinePaymentStatus::PENDING, OnlinePaymentStatus::PROCESSING], true)) {
                    $payment->touch();
                }
                $synced++;
            } catch (PaymentGatewayException $e) {
                $failed++;
                $this->warn("Payment {$payment->id}: {$e->getMessage()}");
            }
        }

        $this->info("Reconciled {$synced} online payment(s), {$failed} failed.");

        return self::SUCCESS;
    }
}
