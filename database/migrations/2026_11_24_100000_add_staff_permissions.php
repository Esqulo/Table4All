<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff accounts (account_type = waiter) get a role (waiter or manager) and their own
     * access per feature (StaffFeature => none/read/write). Invitations say which role the
     * new account starts with. Existing waiters keep exactly what they could do before.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('staff_role', 20)->nullable()->after('restaurant_id');
            $table->jsonb('staff_permissions')->nullable()->after('staff_role');
        });

        Schema::table('waiter_invitations', function (Blueprint $table) {
            $table->string('role', 20)->default('waiter')->after('email');
        });

        DB::table('users')->where('account_type', 'waiter')->update([
            'staff_role' => 'waiter',
            'staff_permissions' => json_encode(['tables' => 'write', 'delivery' => 'write', 'products' => 'read']),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['staff_role', 'staff_permissions']);
        });

        Schema::table('waiter_invitations', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
