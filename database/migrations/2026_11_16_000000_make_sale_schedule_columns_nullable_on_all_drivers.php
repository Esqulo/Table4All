<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026_07_02_000000 only dropped NOT NULL on PostgreSQL, so on SQLite (the
 * test database) dated promotions, which have no days/times, could not be
 * stored at all. PostgreSQL is already correct and is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->json('days')->nullable()->change();
            $table->time('start_time')->nullable()->change();
            $table->time('end_time')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Not reversible: rows of dated promotions would violate NOT NULL.
    }
};
