<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the restaurant discovery page (/descobrir) filters and sorts by:
     * - price_level: 1 to 3 ($, $$, $$$), chosen by the restaurant;
     * - table_capacity: how many tables the venue has; open tables over it give how full it is now;
     * - latitude/longitude: found from the address on the queue (App\Jobs\GeocodeRestaurant),
     *   for "closest first".
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('price_level')->nullable()->after('ifood_url');
            $table->unsignedSmallInteger('table_capacity')->nullable()->after('price_level');
            $table->double('latitude')->nullable()->after('table_capacity');
            $table->double('longitude')->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['price_level', 'table_capacity', 'latitude', 'longitude']);
        });
    }
};
