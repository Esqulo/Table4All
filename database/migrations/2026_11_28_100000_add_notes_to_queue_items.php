<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A note on an ordered item for whoever prepares or delivers it ("sem cebola",
     * "ao ponto"), written by the waiter launching it or the customer ordering it.
     */
    public function up(): void
    {
        Schema::table('queue_items', function (Blueprint $table) {
            $table->string('notes', 140)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('queue_items', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
