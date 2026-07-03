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
        Schema::table('queue_items', function (Blueprint $table) {
            $table->dropForeign(['queue_id']);
        });

        Schema::table('queue_items', function (Blueprint $table) {
            $table->unsignedBigInteger('queue_id')->nullable()->change();
            $table->foreign('queue_id')
                ->references('id')
                ->on('restaurant_queues')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('queue_items', function (Blueprint $table) {
            $table->dropForeign(['queue_id']);
        });

        Schema::table('queue_items', function (Blueprint $table) {
            $table->unsignedBigInteger('queue_id')->nullable(false)->change();
            $table->foreign('queue_id')
                ->references('id')
                ->on('restaurant_queues')
                ->cascadeOnDelete();
        });
    }
};
