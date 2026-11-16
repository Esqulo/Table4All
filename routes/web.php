<?php

use App\Http\Controllers\Admin\AdmissionRequestController as AdminAdmissionRequestController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\AdmissionRequestController;
use App\Http\Controllers\Customer\JoinController as CustomerJoinController;
use App\Http\Controllers\Restaurant\DashboardController;
use App\Http\Controllers\Restaurant\DeliveryController;
use App\Http\Controllers\Restaurant\EstablishmentController;
use App\Http\Controllers\Restaurant\MenuController;
use App\Http\Controllers\Restaurant\ProductController;
use App\Http\Controllers\Restaurant\QueueController;
use App\Http\Controllers\Restaurant\QueueItemController;
use App\Http\Controllers\Restaurant\SaleController;
use App\Http\Controllers\Restaurant\TableController;
use App\Http\Controllers\Restaurant\WaiterController;
use App\Http\Controllers\TableJoinController;
use App\Http\Controllers\WaiterInvitationController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureRestaurant;
use App\Http\Middleware\EnsureRestaurantOrWaiter;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::inertia('/contato', 'contact', [
    'email' => config('app.contact_email'),
])->name('contact');
Route::post('/contato', [AdmissionRequestController::class, 'store'])
    ->middleware('throttle:5,10')
    ->name('contact.store');

Route::get('/menu/{menu}', [MenuController::class, 'show'])->name('menu.show');

Route::get('/mesa', [TableJoinController::class, 'index'])->name('table.join');
Route::get('/mesa/{code}', [TableJoinController::class, 'show'])->name('table.show');

// Public waiter invitation acceptance
Route::get('/garcom/aceitar/{token}', [WaiterInvitationController::class, 'show'])
    ->name('waiter.invite.show');
Route::post('/garcom/aceitar/{token}', [WaiterInvitationController::class, 'store'])
    ->name('waiter.invite.accept');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::prefix('customer')
        ->name('customer.')
        ->group(function () {
            Route::get('mesa', [CustomerJoinController::class, 'index'])->name('join');
            Route::get('mesa/{code}', [CustomerJoinController::class, 'show'])->name('table');
            Route::post('mesa/{code}/pedido', [CustomerJoinController::class, 'order'])->name('order');
        });
});

// Tables (full CRUD) + product listing: accessible by restaurant and waiter
Route::middleware(['auth', 'verified', EnsureRestaurantOrWaiter::class])
    ->prefix('restaurant')
    ->name('restaurant.')
    ->group(function () {
        Route::get('entregas', [DeliveryController::class, 'index'])->name('delivery.index');
        Route::resource('mesas', TableController::class)
            ->names('tables')
            ->parameters(['mesas' => 'table']);
        Route::post('mesas/{table}/pagamentos', [TableController::class, 'addPayment'])
            ->name('tables.add-payment');
        Route::patch('mesas/{table}/fechar', [TableController::class, 'close'])
            ->name('tables.close');
        Route::get('produtos', [ProductController::class, 'index'])
            ->name('products.index');
        Route::patch('queue-items/{queueItem}/pronto', [QueueItemController::class, 'markDone'])
            ->name('queue-items.mark-done');
        Route::patch('queue-items/{queueItem}/entregue', [QueueItemController::class, 'markDelivered'])
            ->name('queue-items.mark-delivered');
    });

// Restaurant-only routes: waiters management, menus, product CRUD
Route::middleware(['auth', 'verified', EnsureRestaurant::class])
    ->prefix('restaurant')
    ->name('restaurant.')
    ->group(function () {
        Route::get('painel', [DashboardController::class, 'index'])->name('dashboards.index');

        // Venue settings: opening hours, photos and logo
        Route::get('estabelecimento', [EstablishmentController::class, 'edit'])->name('establishment.edit');
        Route::put('estabelecimento/horarios', [EstablishmentController::class, 'updateHours'])->name('establishment.hours.update');
        Route::post('estabelecimento/logo', [EstablishmentController::class, 'updateLogo'])->name('establishment.logo.update');
        Route::delete('estabelecimento/logo', [EstablishmentController::class, 'destroyLogo'])->name('establishment.logo.destroy');
        Route::post('estabelecimento/fotos', [EstablishmentController::class, 'storePhotos'])->name('establishment.photos.store');
        Route::put('estabelecimento/fotos/ordem', [EstablishmentController::class, 'reorderPhotos'])->name('establishment.photos.reorder');
        Route::delete('estabelecimento/fotos/{photo}', [EstablishmentController::class, 'destroyPhoto'])->name('establishment.photos.destroy');

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
        Route::put('cardapio/{menu}/layout', [MenuController::class, 'updateLayout'])
            ->name('menus.layout.update');
        Route::post('produtos/{product}/foto', [ProductController::class, 'updatePicture'])
            ->name('products.picture.update');
        Route::resource('produtos', ProductController::class)
            ->except(['show', 'index'])
            ->names('products')
            ->parameters(['produtos' => 'product']);
        Route::resource('filas', QueueController::class)
            ->names('queues')
            ->parameters(['filas' => 'queue']);
        Route::resource('promocoes', SaleController::class)
            ->only(['index', 'create', 'store', 'edit', 'update', 'destroy'])
            ->names('sales')
            ->parameters(['promocoes' => 'sale']);
    });

Route::middleware(['auth', 'verified', EnsureAdmin::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::resource('categorias', CategoryController::class)
            ->only(['index', 'store', 'destroy'])
            ->names('categories')
            ->parameters(['categorias' => 'category']);
        Route::resource('solicitacoes', AdminAdmissionRequestController::class)
            ->only(['index', 'update'])
            ->names('admission-requests')
            ->parameters(['solicitacoes' => 'admissionRequest']);
    });

require __DIR__.'/settings.php';
