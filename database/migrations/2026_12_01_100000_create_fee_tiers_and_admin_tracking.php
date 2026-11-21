<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The admin panel:
     *
     * - Fee tiers move from config/payments.php to a table the admin edits. The two tiers
     *   that were configured are kept with their keys, so users.fee_tier and the tier stored
     *   on each online payment still point at them.
     * - A restaurant may have its own commission, which overrides its tier's.
     * - When each account last signed in and was last seen, for the platform's (anonymous)
     *   activity figures.
     * - Indexes for the platform-wide figures, which filter by date across every restaurant
     *   (the existing indexes all start with the restaurant).
     */
    public function up(): void
    {
        Schema::create('fee_tiers', function (Blueprint $table) {
            $table->id();
            // What users.fee_tier and online_payments.fee_tier store; never changes.
            $table->string('key', 32)->unique();
            $table->string('name', 60);
            // The platform's share of each online payment, in percent.
            $table->decimal('fee_percent', 5, 2);
            $table->timestamps();
        });

        $now = now();
        DB::table('fee_tiers')->insert([
            ['key' => 'default', 'name' => 'Padrão', 'fee_percent' => 4.5, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'founder', 'name' => 'Fundador', 'fee_percent' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->decimal('fee_percent_override', 5, 2)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->index('fee_tier', 'users_fee_tier_idx');
            $table->index('last_login_at', 'users_last_login_idx');
            $table->index('last_seen_at', 'users_last_seen_idx');
            $table->index(['account_type', 'created_at'], 'users_type_created_idx');
        });

        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->index('closed_at', 'restaurant_tables_closed_idx');
        });

        Schema::table('table_payments', function (Blueprint $table) {
            $table->index('created_at', 'table_payments_created_idx');
        });

        Schema::table('queue_items', function (Blueprint $table) {
            $table->index('created_at', 'queue_items_created_idx');
        });

        Schema::table('online_payments', function (Blueprint $table) {
            $table->index(['status', 'paid_at'], 'online_payments_status_paid_idx');
        });

        Schema::table('restaurant_table_customers', function (Blueprint $table) {
            $table->index('created_at', 'restaurant_table_customers_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_table_customers', fn (Blueprint $table) => $table->dropIndex('restaurant_table_customers_created_idx'));
        Schema::table('online_payments', fn (Blueprint $table) => $table->dropIndex('online_payments_status_paid_idx'));
        Schema::table('queue_items', fn (Blueprint $table) => $table->dropIndex('queue_items_created_idx'));
        Schema::table('table_payments', fn (Blueprint $table) => $table->dropIndex('table_payments_created_idx'));
        Schema::table('restaurant_tables', fn (Blueprint $table) => $table->dropIndex('restaurant_tables_closed_idx'));

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_fee_tier_idx');
            $table->dropIndex('users_last_login_idx');
            $table->dropIndex('users_last_seen_idx');
            $table->dropIndex('users_type_created_idx');
            $table->dropColumn(['fee_percent_override', 'last_login_at', 'last_seen_at']);
        });

        Schema::dropIfExists('fee_tiers');
    }
};
