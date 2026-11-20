<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers at a table call the waiter or ask for the bill; staff see the open calls
     * on their calls board and mark them answered. A call is open until resolved:
     * answered by staff, cancelled by the table, or the table closed.
     *
     * At most one open call per table and type (partial unique index), so a double tap or
     * a retried request never queues the same call twice.
     */
    public function up(): void
    {
        Schema::create('table_calls', function (Blueprint $table) {
            $table->id();
            // The restaurant owner's id, like every restaurant-owned row.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_table_id')->constrained('restaurant_tables')->cascadeOnDelete();
            $table->string('type', 10); // waiter | bill
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 10)->nullable(); // answered | cancelled | closed
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The calls board: a restaurant's open calls, oldest first.
            $table->index(['user_id', 'resolved_at', 'created_at'], 'table_calls_board_idx');
        });

        DB::statement('CREATE UNIQUE INDEX table_calls_one_open_idx ON table_calls (restaurant_table_id, type) WHERE resolved_at IS NULL');

        // Staff who could work the delivery board get the same access to the calls board.
        DB::table('users')
            ->where('account_type', 'waiter')
            ->whereNotNull('staff_permissions')
            ->select(['id', 'staff_permissions'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $permissions = json_decode((string) $row->staff_permissions, true) ?: [];
                $permissions['calls'] = $permissions['delivery'] ?? 'none';
                DB::table('users')->where('id', $row->id)->update(['staff_permissions' => json_encode($permissions)]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_calls');
    }
};
