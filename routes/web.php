<?php

use App\Http\Controllers\Restaurant\ProductController;
use App\Http\Middleware\EnsureRestaurant;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

Route::middleware(['auth', 'verified', EnsureRestaurant::class])
    ->prefix('restaurant')
    ->name('restaurant.')
    ->group(function () {
        Route::inertia('mesas', 'restaurant/tables')->name('tables');
        Route::resource('produtos', ProductController::class)
            ->except(['show'])
            ->names('products')
            ->parameters(['produtos' => 'product']);
    });

require __DIR__.'/settings.php';
