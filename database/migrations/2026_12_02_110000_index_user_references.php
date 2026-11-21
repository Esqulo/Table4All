<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PostgreSQL doesn't index foreign keys. These columns point at users and had none, so
     * deleting an account (ON DELETE SET NULL scans each referencing table) and exporting a
     * person's data (LGPD Art. 18) read whole tables. Partial: most rows have no such user.
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS queue_items_ordered_by_idx ON queue_items (ordered_by_user_id) WHERE ordered_by_user_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS table_payments_registered_by_idx ON table_payments (registered_by_id) WHERE registered_by_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS table_calls_requested_by_idx ON table_calls (requested_by_user_id) WHERE requested_by_user_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS table_calls_resolved_by_idx ON table_calls (resolved_by_user_id) WHERE resolved_by_user_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS restaurant_tables_waiter_idx ON restaurant_tables (waiter_id) WHERE waiter_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS admin_invitations_invited_by_idx ON admin_invitations (invited_by_id) WHERE invited_by_id IS NOT NULL');

        // What `privacy:prune` deletes each day: old resolved calls and expired sign-in links.
        DB::statement('CREATE INDEX IF NOT EXISTS table_calls_resolved_at_idx ON table_calls (resolved_at) WHERE resolved_at IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS customer_link_tokens_expires_idx ON customer_link_tokens (expires_at)');
    }

    public function down(): void
    {
        foreach ([
            'queue_items_ordered_by_idx',
            'table_payments_registered_by_idx',
            'table_calls_requested_by_idx',
            'table_calls_resolved_by_idx',
            'restaurant_tables_waiter_idx',
            'admin_invitations_invited_by_idx',
            'table_calls_resolved_at_idx',
            'customer_link_tokens_expires_idx',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }
};
