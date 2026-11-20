<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            // How many payments may use the coupon in total, across every table and
            // customer; null means unlimited.
            $table->unsignedInteger('max_uses')->nullable()->after('expires_at');
        });

        Schema::table('online_payments', function (Blueprint $table) {
            // Counting a coupon's uses (limited coupons, the coupons page).
            $table->index(['coupon_id', 'status'], 'online_payments_coupon_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('online_payments', function (Blueprint $table) {
            $table->dropIndex('online_payments_coupon_status_idx');
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('max_uses');
        });
    }
};
