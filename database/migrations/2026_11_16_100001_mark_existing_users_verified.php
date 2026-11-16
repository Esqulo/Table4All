<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Email verification becomes mandatory with this release (User implements
 * MustVerifyEmail). Accounts created before it were never asked to verify;
 * locking them out would stop restaurants and waiters mid-service, so they
 * are grandfathered in. New accounts must verify.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        // Can't tell grandfathered accounts from genuinely verified ones.
    }
};
