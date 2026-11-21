<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;

/**
 * When each account last signed in (password, passkey, 2FA, an accepted invite or the
 * "remember me" cookie), for the admin panel's anonymous activity figures. Written
 * straight to the table so it doesn't touch updated_at or fire the model's events.
 */
class RecordLogin
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $now = now();

        DB::table('users')->where('id', $event->user->id)->update([
            'last_login_at' => $now,
            'last_seen_at' => $now,
        ]);
    }
}
