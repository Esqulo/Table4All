<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reports over a long history (measured with 50k closed tables and 400k bill lines):
     *
     * - What a customer spent (rewards page) filters bill lines by ordered_by_user_id,
     *   which had no index: a scan of every restaurant's lines, 50 ms and growing with
     *   the platform. With it, 0.3 ms.
     * - The dashboard sums a restaurant's lines over up to 90 days. This index holds
     *   everything it reads, so the lines of the restaurant's tables are read straight
     *   from it instead of scanning every restaurant's lines (with random_page_cost set
     *   for SSDs, see docker-compose.yml): 600 ms to 220 ms for a venue with 5000 tables
     *   in the period.
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX rtp_buyer_idx ON restaurant_table_product (ordered_by_user_id)');
        DB::statement('CREATE INDEX rtp_table_cover_idx ON restaurant_table_product (restaurant_table_id) INCLUDE (product_id, price, quantity, ordered_by_user_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS rtp_table_cover_idx');
        DB::statement('DROP INDEX IF EXISTS rtp_buyer_idx');
    }
};
