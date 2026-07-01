<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'queue_id')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('queue_id')
                ->nullable()
                ->after('category_id')
                ->constrained('restaurant_queues')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\RestaurantQueue::class);
            $table->dropColumn('queue_id');
        });
    }
};