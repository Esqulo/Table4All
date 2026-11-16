<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL does not index foreign keys automatically, so every
 * "WHERE user_id = ?" / "WHERE restaurant_table_id = ?" on the hot paths
 * (tables board, kitchen queue, delivery board, customer menu) was a
 * sequential scan. These indexes match the filters those screens use.
 *
 * @see docs/performance.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            // Open tables of a restaurant (tables board, dashboard filters by closed_at).
            $table->index(['user_id', 'closed_at'], 'restaurant_tables_user_closed_idx');
            // QR / access-code lookup on every customer page load and order.
            $table->index('access_code', 'restaurant_tables_access_code_idx');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index(['user_id', 'name'], 'products_user_name_idx');
        });

        Schema::table('queue_items', function (Blueprint $table) {
            $table->index(['restaurant_table_id', 'status'], 'queue_items_table_status_idx');
            $table->index(['queue_id', 'status'], 'queue_items_queue_status_idx');
            $table->index(['status', 'updated_at'], 'queue_items_status_updated_idx');
        });

        Schema::table('table_payments', function (Blueprint $table) {
            $table->index('restaurant_table_id', 'table_payments_table_idx');
        });

        Schema::table('restaurant_table_product', function (Blueprint $table) {
            // The unique (table, product, user) index already covers lookups by table.
            $table->index('product_id', 'rtp_product_idx');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->index(['user_id', 'type'], 'sales_user_type_idx');
        });

        Schema::table('restaurant_queues', function (Blueprint $table) {
            $table->index('user_id', 'restaurant_queues_user_idx');
        });

        Schema::table('menus', function (Blueprint $table) {
            $table->index('user_id', 'menus_user_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('restaurant_id', 'users_restaurant_idx');
        });

        Schema::table('waiter_invitations', function (Blueprint $table) {
            $table->index('restaurant_id', 'waiter_invitations_restaurant_idx');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropIndex('restaurant_tables_user_closed_idx');
            $table->dropIndex('restaurant_tables_access_code_idx');
        });
        Schema::table('products', fn (Blueprint $table) => $table->dropIndex('products_user_name_idx'));
        Schema::table('queue_items', function (Blueprint $table) {
            $table->dropIndex('queue_items_table_status_idx');
            $table->dropIndex('queue_items_queue_status_idx');
            $table->dropIndex('queue_items_status_updated_idx');
        });
        Schema::table('table_payments', fn (Blueprint $table) => $table->dropIndex('table_payments_table_idx'));
        Schema::table('restaurant_table_product', fn (Blueprint $table) => $table->dropIndex('rtp_product_idx'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropIndex('sales_user_type_idx'));
        Schema::table('restaurant_queues', fn (Blueprint $table) => $table->dropIndex('restaurant_queues_user_idx'));
        Schema::table('menus', fn (Blueprint $table) => $table->dropIndex('menus_user_idx'));
        Schema::table('users', fn (Blueprint $table) => $table->dropIndex('users_restaurant_idx'));
        Schema::table('waiter_invitations', fn (Blueprint $table) => $table->dropIndex('waiter_invitations_restaurant_idx'));
    }
};
