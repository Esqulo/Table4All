<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer incentives a restaurant switched on (a row per enabled incentive).
        // enabled_at marks when: only what customers do from then on counts toward it.
        Schema::create('restaurant_incentives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('users')->cascadeOnDelete();
            $table->string('incentive', 32);
            $table->timestamp('enabled_at');
            $table->timestamps();

            $table->unique(['restaurant_id', 'incentive']);
        });

        // A coupon only one customer can use (loyalty rewards); null for codes anyone can type.
        Schema::table('coupons', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('user_id')->constrained('users')->cascadeOnDelete();
            $table->index('customer_id');
        });

        // Loyalty rewards handed out: one per checkpoint, keyed by the local day of the
        // visit that reached it (a customer visits a place at most once a day), so the
        // same checkpoint is never rewarded twice.
        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained('users')->cascadeOnDelete();
            $table->date('earned_on');
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'restaurant_id', 'earned_on']);
            $table->index('restaurant_id');
            $table->index('coupon_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_rewards');

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::dropIfExists('restaurant_incentives');
    }
};
