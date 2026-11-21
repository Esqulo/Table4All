<?php

use App\Enums\AccountType;
use App\Jobs\GeocodeRestaurant;
use App\Models\Product;
use App\Models\User;
use App\Support\ImageOptimizer;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('products:optimize-images', function () {
    $optimized = 0;

    Product::withTrashed()
        ->whereNotNull('picture')
        ->select(['id', 'picture'])
        ->chunkById(100, function ($products) use (&$optimized) {
            foreach ($products as $product) {
                $old = (string) $product->picture;
                $new = ImageOptimizer::reprocessStored($old);

                if ($new === null) {
                    continue;
                }

                $product->forceFill(['picture' => $new])->saveQuietly();
                Storage::disk('public')->delete($old);
                $optimized++;
            }
        });

    $this->info("Optimized {$optimized} product picture(s).");
})->purpose('Resize and re-encode stored product pictures (see docs/performance.md)');

Artisan::command('restaurants:geocode', function () {
    $queued = 0;

    User::where('account_type', AccountType::RESTAURANT)
        ->whereNotNull('address')
        ->whereNull('latitude')
        ->select(['id'])
        ->chunkById(100, function ($restaurants) use (&$queued) {
            foreach ($restaurants as $restaurant) {
                // Spaced out: Nominatim's usage policy allows one request per second.
                GeocodeRestaurant::dispatch($restaurant->id)->delay(now()->addSeconds($queued * 2));
                $queued++;
            }
        });

    $this->info("Queued {$queued} restaurant address(es) to find on the map.");
})->purpose('Find on the map the restaurant addresses saved before geocoding existed (discovery page)');

// "Hoje tem!" stories last 24 hours: delete them and their files once they expire.
Schedule::command('stories:prune')->everyFiveMinutes()->withoutOverlapping();

// Online payments: catch up on checkouts whose webhook never arrived and on
// transfers to restaurants that failed (see App\Console\Commands\ReconcilePayments).
Schedule::command('payments:reconcile')->everyMinute()->withoutOverlapping();

// LGPD: delete personal data past its retention period (config/legal.php, docs/privacy.md),
// and password reset tokens that already expired.
Schedule::command('privacy:prune')->dailyAt('04:10')->withoutOverlapping();
Schedule::command('auth:clear-resets')->dailyAt('04:20');
