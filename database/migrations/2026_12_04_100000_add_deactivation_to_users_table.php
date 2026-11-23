<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An admin can switch an account off (abuse, fraud, a restaurant that stopped paying)
     * without deleting anything: it can't sign in, and a restaurant's team, public profile,
     * tables and menus go with it. Switching it back on restores everything.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->index();
            // The admin who did it (null once that admin's account is gone).
            $table->foreignId('deactivated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('deactivation_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn(['deactivated_at', 'deactivation_reason']);
        });
    }
};
