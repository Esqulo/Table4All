<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the old 2-column composite primary key
        Schema::table('restaurant_table_product', function (Blueprint $table) {
            $table->dropPrimary(['restaurant_table_id', 'product_id']);
        });

        // Add ordered_by_user_id nullable (existing rows become NULL = "anonymous")
        Schema::table('restaurant_table_product', function (Blueprint $table) {
            $table->foreignId('ordered_by_user_id')
                ->nullable()
                ->after('product_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        // Unique index excluding NULLs (PostgreSQL: NULLs are distinct in UNIQUE)
        // This allows one row per (table, product, user) while not conflicting on NULL rows
        Schema::table('restaurant_table_product', function (Blueprint $table) {
            $table->unique(
                ['restaurant_table_id', 'product_id', 'ordered_by_user_id'],
                'rtp_unique_per_user',
            );
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_table_product', function (Blueprint $table) {
            $table->dropUnique('rtp_unique_per_user');
            $table->dropForeign(['ordered_by_user_id']);
            $table->dropColumn('ordered_by_user_id');
            $table->primary(['restaurant_table_id', 'product_id']);
        });
    }
};
