<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Models\RestaurantPhoto;
use App\Models\RestaurantPost;
use App\Models\RestaurantStory;
use App\Models\User;
use App\Models\WaiterInvitation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Closes a restaurant's account at its request (LGPD Art. 16 and 18 VI) without deleting the
 * records the law requires keeping: tables, bills, payments and products stay, tied to an
 * anonymous account; the owner's identity, contact details, public profile and media go, and
 * so do the staff logins, which only existed to work there.
 *
 * Restaurants can't delete themselves (ProfileDeleteRequest), because deleting the user would
 * cascade through every bill. See docs/privacy.md.
 */
#[Signature('restaurants:close {id : The restaurant owner\'s user id} {--force : Skip the confirmation}')]
#[Description('Close a restaurant account: remove its personal and public data, keep its financial records')]
final class CloseRestaurant extends Command
{
    public function handle(): int
    {
        $restaurant = User::where('account_type', AccountType::RESTAURANT)->find((int) $this->argument('id'));

        if ($restaurant === null) {
            $this->error('No restaurant with that id.');

            return self::FAILURE;
        }

        $staff = User::where('restaurant_id', $restaurant->id)->get(['id', 'avatar']);

        if (! $this->option('force') && ! $this->confirm(sprintf(
            'Close "%s" (%s)? Its profile, media and %d staff account(s) are removed; bills and payments are kept. This cannot be undone.',
            $restaurant->name, $restaurant->email, $staff->count(),
        ))) {
            return self::FAILURE;
        }

        $files = array_values(array_filter([$restaurant->avatar, $restaurant->logo, $restaurant->banner]));
        $files = [...$files, ...RestaurantPhoto::where('user_id', $restaurant->id)->pluck('path')->all()];
        $files = [...$files, ...RestaurantPost::where('user_id', $restaurant->id)->whereNotNull('image')->pluck('image')->all()];
        $files = [...$files, ...$staff->pluck('avatar')->filter()->all()];
        $stories = RestaurantStory::where('user_id', $restaurant->id)->get(['id', 'path', 'poster_path', 'source_path']);

        DB::transaction(function () use ($restaurant, $staff): void {
            RestaurantPhoto::where('user_id', $restaurant->id)->delete();
            RestaurantPost::where('user_id', $restaurant->id)->delete(); // comments and reactions cascade
            RestaurantStory::where('user_id', $restaurant->id)->delete();
            WaiterInvitation::where('restaurant_id', $restaurant->id)->delete();
            DB::table('restaurant_reviews')->where('restaurant_id', $restaurant->id)->delete();

            // Staff rows point at bills only through nullable keys (who ordered, who registered a payment).
            User::whereIn('id', $staff->modelKeys())->delete();

            $restaurant->forceFill([
                'name' => __('privacy.closed_restaurant_name', ['id' => $restaurant->id]),
                // A reserved domain (RFC 2606): never delivers, never collides with a real sign-up.
                'email' => "closed-{$restaurant->id}-".Str::lower(Str::random(12)).'@closed.invalid',
                'phone' => null,
                'avatar' => null,
                'logo' => null,
                'banner' => null,
                'handle' => "closed-{$restaurant->id}",
                'profile_public' => false,
                'address' => null,
                'ifood_url' => null,
                'latitude' => null,
                'longitude' => null,
                'password' => Str::random(64),
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            DB::table('passkeys')->where('user_id', $restaurant->id)->delete();
            DB::table('sessions')->whereIn('user_id', [$restaurant->id, ...$staff->modelKeys()])->delete();
        });

        Storage::disk('public')->delete($files);
        $stories->each(fn (RestaurantStory $story) => $story->deleteFiles());

        $this->info("Restaurant #{$restaurant->id} closed: personal data removed, financial records kept.");

        return self::SUCCESS;
    }
}
