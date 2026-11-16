<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tables closed before the "abandoned" status existed left their undelivered
 * items pending forever. They mean the same thing (the customer left without
 * receiving them), and while pending they would block deleting their queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('queue_items')
            ->whereIn('status', ['pending', 'done'])
            ->whereIn('restaurant_table_id', DB::table('restaurant_tables')->whereNotNull('closed_at')->select('id'))
            ->update(['status' => 'abandoned']);
    }

    public function down(): void
    {
        // Their previous status (pending or done) is not recorded.
    }
};
