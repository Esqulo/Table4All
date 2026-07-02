<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sales')->truncate();

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at']);
            $table->json('days');        // e.g. [1,2,3,4] — 0=Sun … 6=Sat
            $table->time('start_time'); // e.g. "17:00:00"
            $table->time('end_time');   // e.g. "20:00:00"
        });
    }

    public function down(): void
    {
        DB::table('sales')->truncate();

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['days', 'start_time', 'end_time']);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
        });
    }
};
