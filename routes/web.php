<?php

use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Restaurant\MenuController;
use App\Http\Controllers\Restaurant\ProductController;
use App\Http\Controllers\Restaurant\TableController;
use App\Http\Controllers\Restaurant\WaiterController;
use App\Http\Controllers\WaiterInvitationController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureRestaurant;
use App\Http\Middleware\EnsureRestaurantOrWaiter;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('/menu/{menu}', [MenuController::class, 'show'])->name('menu.show');

// Public waiter invitation acceptance
Route::get('/garcom/aceitar/{token}', [WaiterInvitationController::class, 'show'])
    ->name('waiter.invite.show');
Route::post('/garcom/aceitar/{token}', [WaiterInvitationController::class, 'store'])
    ->name('waiter.invite.accept');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

// Tables (full CRUD) + product listing: accessible by restaurant and waiter
Route::middleware(['auth', 'verified', EnsureRestaurantOrWaiter::class])
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
        Route::get('produtos', [ProductController::class, 'index'])
            ->name('products.index');
    });

// Restaurant-only routes: waiters management, menus, product CRUD
Route::middleware(['auth', 'verified', EnsureRestaurant::class])
    ->prefix('restaurant')
    ->name('restaurant.')
    ->group(function () {
        // cancelInvitation must be declared before the resource so it's matched first
        Route::delete('garcons/convites/{invitation}', [WaiterController::class, 'cancelInvitation'])
            ->name('waiters.cancel-invitation');
        Route::resource('garcons', WaiterController::class)
            ->only(['index', 'store', 'destroy'])
            ->names('waiters')
            ->parameters(['garcons' => 'waiter']);
        Route::resource('cardapio', MenuController::class)
            ->only(['index', 'create', 'store'])
            ->names('menus')
            ->parameters(['cardapio' => 'menu']);
        Route::get('cardapio/{menu}/imprimir', [MenuController::class, 'printMenu'])
            ->name('menus.print');
        Route::resource('produtos', ProductController::class)
            ->except(['show', 'index'])
            ->names('products')
            ->parameters(['produtos' => 'product']);
    });

Route::middleware(['auth', 'verified', EnsureAdmin::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::resource('categorias', CategoryController::class)
            ->only(['index', 'store', 'destroy'])
            ->names('categories')
            ->parameters(['categorias' => 'category']);
    });

require __DIR__.'/settings.php';
