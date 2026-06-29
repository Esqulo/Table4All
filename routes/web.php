<?php

use App\Http\Controllers\Restaurant\ProductController;
use App\Http\Controllers\Restaurant\TableController;
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
        Route::resource('mesas', TableController::class)
            ->names('tables')
            ->parameters(['mesas' => 'table']);
        Route::post('mesas/{table}/pagamentos', [TableController::class, 'addPayment'])
            ->name('tables.add-payment');
        Route::patch('mesas/{table}/fechar', [TableController::class, 'close'])
            ->name('tables.close');
        Route::resource('produtos', ProductController::class)
            ->except(['show'])
            ->names('products')
            ->parameters(['produtos' => 'product']);
    });

require __DIR__.'/settings.php';
