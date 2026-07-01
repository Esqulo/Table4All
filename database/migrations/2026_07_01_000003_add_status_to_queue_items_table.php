<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('queue_items', 'status')) {
            return;
        }

        Schema::table('queue_items', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('queue_items', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
