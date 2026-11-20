<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The waiter in charge of a table (App\Support\TableAssignment). Set to whoever on the
     * team opens it, taken by a waiter when free, released by them, or set by the owner or
     * a manager. Kept after the table closes, as a record of who served it.
     *
     * The restaurant turns the feature on or off in its venue settings.
     */
    public function up(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->foreignId('waiter_id')->nullable()->after('responsible_user_id')
                ->constrained('users')->nullOnDelete();
            // "My tables" and clearing a removed member's tables.
            $table->index(['user_id', 'waiter_id'], 'restaurant_tables_user_waiter_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('table_assignment_enabled')->default(true)->after('table_capacity');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropIndex('restaurant_tables_user_waiter_idx');
            $table->dropConstrainedForeignId('waiter_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('table_assignment_enabled');
        });
    }
};
