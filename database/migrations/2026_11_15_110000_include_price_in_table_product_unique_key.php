<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A table line is (product, customer, price). Without the price in the key a
 * later delivery of the same product merged into the existing line at that
 * line's price, so an item ordered during a promotion was billed at full
 * price (or the reverse). The new key is strictly wider than the old one, so
 * existing rows can't conflict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_table_product', function (Blueprint $table) {
            $table->dropUnique('rtp_unique_per_user');
        });

        Schema::table('restaurant_table_product', function (Blueprint $table) {
            $table->unique(
                ['restaurant_table_id', 'product_id', 'ordered_by_user_id', 'price'],
                'rtp_unique_per_user_price',
            );
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_table_product', function (Blueprint $table) {
            $table->dropUnique('rtp_unique_per_user_price');
        });

        Schema::table('restaurant_table_product', function (Blueprint $table) {
            $table->unique(
                ['restaurant_table_id', 'product_id', 'ordered_by_user_id'],
                'rtp_unique_per_user',
            );
        });
    }
};
