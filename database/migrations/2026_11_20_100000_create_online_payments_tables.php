<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Online payments through Stripe Connect: the restaurant's Stripe account and
     * the platform's terms with it, discount coupons, and each checkout a customer
     * starts. Money columns of the new tables are integer cents.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The restaurant's connected Stripe account; charges_enabled is mirrored from Stripe.
            $table->string('stripe_account_id', 64)->nullable()->unique();
            $table->boolean('stripe_charges_enabled')->default(false);
            $table->boolean('stripe_details_submitted')->default(false);
            // Key of config('payments.tiers'): the platform's share of each payment.
            $table->string('fee_tier', 32)->nullable();
            // The platform refunds Stripe's processing fee to this restaurant.
            $table->boolean('platform_pays_stripe_fees')->default(false);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            // The restaurant that offers it (and pays for the discount); null for
            // the platform's own coupons, valid everywhere and paid by the platform.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('discount_type', 10);
            $table->decimal('discount_value', 10, 2);
            $table->decimal('max_discount', 10, 2)->nullable();
            $table->timestamp('expires_at')->nullable();
            // false: once used at a table, no other coupon is accepted there (and it
            // is refused where one was used). true: each customer at the table can
            // use one coupon on their own payment.
            $table->boolean('multiple_per_table')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
        });

        // One live code per owner (the platform counting as one), case-insensitive.
        DB::statement('CREATE UNIQUE INDEX coupons_owner_code_unique ON coupons (COALESCE(user_id, 0), code) WHERE deleted_at IS NULL');

        Schema::create('online_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_table_id')->constrained('restaurant_tables')->cascadeOnDelete();
            // Who paid.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restaurant_id')->constrained('users')->cascadeOnDelete();
            $table->string('stripe_account_id', 64);
            $table->string('status', 16);
            $table->string('request_key', 64)->nullable();
            // Prefix of the idempotency keys sent to Stripe. Random, not the row id: Stripe keeps
            // keys for 24 hours per account, and ids repeat across environments and database resets.
            $table->uuid('stripe_key')->unique();

            // amount = what comes off the bill; charge = what the card pays (amount - discount).
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('charge_cents');
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->boolean('coupon_by_platform')->default(false);
            $table->boolean('coupon_multiple_per_table')->default(false);

            // The platform's terms when the payment started.
            $table->string('fee_tier', 32);
            $table->unsignedInteger('fee_rate_bps');
            $table->unsignedInteger('fee_cents');
            $table->unsignedInteger('application_fee_cents');
            $table->boolean('platform_pays_stripe_fees')->default(false);

            $table->string('checkout_session_id', 255)->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->string('payment_intent_id', 255)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // What came off the bill once confirmed. Less than the amount when the bill was
            // already (partly) paid by then; the difference is refunded to the customer.
            $table->unsignedInteger('credited_cents')->nullable();
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->string('refund_id', 255)->nullable();
            $table->timestamp('refunded_at')->nullable();

            // What the platform gives the restaurant afterwards. Stripe Brazil doesn't let the
            // platform transfer money freely to the account, so: Stripe's fee (sponsored
            // restaurants) comes back as a refund of the platform's own commission, up to that
            // commission; a platform coupon's discount beyond the commission is owed outside
            // Stripe (admin payouts report) until marked paid.
            $table->unsignedInteger('stripe_fee_cents')->nullable();
            $table->unsignedInteger('fee_refund_cents')->nullable();
            $table->string('fee_refund_id', 255)->nullable();
            $table->unsignedInteger('owed_cents')->default(0);
            $table->timestamp('owed_paid_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['restaurant_table_id', 'status']);
            $table->index(['status', 'updated_at']);
            $table->index('coupon_id');
            $table->index('restaurant_id');
            // The admin's payouts report: what is still owed outside Stripe.
            $table->index(['owed_paid_at', 'restaurant_id']);
            $table->unique(['user_id', 'request_key']);
        });

        Schema::table('table_payments', function (Blueprint $table) {
            $table->foreignId('online_payment_id')->nullable()->constrained('online_payments')->nullOnDelete();
            $table->index('online_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('table_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('online_payment_id');
        });

        Schema::dropIfExists('online_payments');
        Schema::dropIfExists('coupons');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['stripe_account_id']);
            $table->dropColumn([
                'stripe_account_id',
                'stripe_charges_enabled',
                'stripe_details_submitted',
                'fee_tier',
                'platform_pays_stripe_fees',
            ]);
        });
    }
};
