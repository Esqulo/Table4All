<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mercado Pago next to Stripe: the restaurant links its Mercado Pago account
     * (OAuth) and chooses which provider customers pay through. Online payments
     * record their provider, and the Stripe-only column names become neutral.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The provider new checkouts go to (null: none chosen yet).
            $table->string('payment_provider', 16)->nullable();
            // The restaurant's Mercado Pago account, linked through the platform's app (OAuth).
            // Tokens are encrypted by the model; they renew before expiring (180 days).
            $table->string('mercadopago_user_id', 32)->nullable();
            $table->text('mercadopago_access_token')->nullable();
            $table->text('mercadopago_refresh_token')->nullable();
            $table->timestamp('mercadopago_token_expires_at')->nullable();

            // Webhooks and token lookups find the restaurant by its Mercado Pago account.
            $table->index('mercadopago_user_id');
        });

        DB::table('users')->whereNotNull('stripe_account_id')->update(['payment_provider' => 'stripe']);

        Schema::table('online_payments', function (Blueprint $table) {
            $table->string('provider', 16)->default('stripe');
            $table->renameColumn('stripe_account_id', 'account_id');
            $table->renameColumn('stripe_key', 'reference');
            $table->renameColumn('stripe_fee_cents', 'processing_fee_cents');
        });

        // Existing rows are Stripe's; new ones always say which provider they're on.
        // (A Mercado Pago notification finds its payment by the unique reference.)
        Schema::table('online_payments', function (Blueprint $table) {
            $table->string('provider', 16)->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('online_payments', function (Blueprint $table) {
            $table->renameColumn('account_id', 'stripe_account_id');
            $table->renameColumn('reference', 'stripe_key');
            $table->renameColumn('processing_fee_cents', 'stripe_fee_cents');
            $table->dropColumn('provider');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['mercadopago_user_id']);
            $table->dropColumn([
                'payment_provider',
                'mercadopago_user_id',
                'mercadopago_access_token',
                'mercadopago_refresh_token',
                'mercadopago_token_expires_at',
            ]);
        });
    }
};
