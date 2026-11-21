<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the product sold out ("esgotado"), null while it is available. Sold-out
     * products stay on the menus, marked, and can't be ordered or launched until
     * someone with the permission (products or availability) marks them back.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('sold_out_at')->nullable()->after('price_type');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('sold_out_at');
        });
    }
};
