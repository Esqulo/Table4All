<?php

use App\Http\Controllers\Admin\AdmissionRequestController as AdminAdmissionRequestController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Admin\PayoutController as AdminPayoutController;
use App\Http\Controllers\Admin\RestaurantController as AdminRestaurantController;
use App\Http\Controllers\AdmissionRequestController;
use App\Http\Controllers\Customer\JoinController as CustomerJoinController;
use App\Http\Controllers\Customer\PaymentController as CustomerPaymentController;
use App\Http\Controllers\Customer\RewardController as CustomerRewardController;
use App\Http\Controllers\PostInteractionController;
use App\Http\Controllers\Restaurant\CouponController;
use App\Http\Controllers\Restaurant\DashboardController;
use App\Http\Controllers\Restaurant\DeliveryController;
use App\Http\Controllers\Restaurant\EstablishmentController;
use App\Http\Controllers\Restaurant\FinanceController;
use App\Http\Controllers\Restaurant\IncentiveController;
use App\Http\Controllers\Restaurant\MenuController;
use App\Http\Controllers\Restaurant\PostController;
use App\Http\Controllers\Restaurant\ProductController;
use App\Http\Controllers\Restaurant\QueueController;
use App\Http\Controllers\Restaurant\QueueItemController;
use App\Http\Controllers\Restaurant\SaleController;
use App\Http\Controllers\Restaurant\StoryController;
use App\Http\Controllers\Restaurant\TableController;
use App\Http\Controllers\Restaurant\WaiterController;
use App\Http\Controllers\RestaurantProfileController;
use App\Http\Controllers\SocialFeedController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TableJoinController;
use App\Http\Controllers\WaiterInvitationController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureNotStaff;
use App\Http\Middleware\EnsureRestaurant;
use App\Http\Middleware\EnsureRestaurantOrWaiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::inertia('/contato', 'contact', [
    'email' => config('app.contact_email'),
])->name('contact');
Route::post('/contato', [AdmissionRequestController::class, 'store'])
    ->middleware('throttle:5,10')
    ->name('contact.store');

Route::get('/menu/{menu}', [MenuController::class, 'show'])->name('menu.show');

// Public profile of a restaurant (the social side: anyone can see it)
Route::get('/vempro/{handle}', [RestaurantProfileController::class, 'show'])
    ->where('handle', '@?[A-Za-z0-9_-]{1,40}')
    ->name('restaurant-profile.show');
Route::get('/vempro/{handle}/p/{post}', [RestaurantProfileController::class, 'showPost'])
    ->where('handle', '@?[A-Za-z0-9_-]{1,40}')
    ->whereNumber('post')
    ->name('restaurant-profile.post');

// The feed's former addresses; old links and bookmarks still reach it, search term included.
foreach (['/vempro', '/painel'] as $formerFeedUrl) {
    Route::get($formerFeedUrl, fn (Request $request) => redirect()->to(
        '/feed'.($request->getQueryString() ? '?'.$request->getQueryString() : ''),
        301,
    ));
}

// Stripe's notifications (signed; CSRF-exempt in bootstrap/app.php).
Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

Route::get('/mesa', [TableJoinController::class, 'index'])->name('table.join');
Route::get('/mesa/{code}', [TableJoinController::class, 'show'])->name('table.show');

// Public waiter invitation acceptance
Route::get('/garcom/aceitar/{token}', [WaiterInvitationController::class, 'show'])
    ->name('waiter.invite.show');
Route::post('/garcom/aceitar/{token}', [WaiterInvitationController::class, 'store'])
    ->name('waiter.invite.accept');

Route::middleware(['auth', 'verified'])->group(function () {
    // Customers land on the social feed; other accounts get the dashboard.
    Route::get('dashboard', [SocialFeedController::class, 'home'])->name('dashboard');

    // The social network for consumers: posts from every public restaurant, newest first.
    // Its own address: /vempro is the public face of each restaurant (/vempro/{handle}).
    Route::get('feed', [SocialFeedController::class, 'index'])->name('social.feed');

    Route::prefix('customer')
        ->name('customer.')
        ->middleware(EnsureNotStaff::class)
        ->group(function () {
            // Rewards: level, loyalty cards, reward coupons, tables and payments.
            Route::get('recompensas', [CustomerRewardController::class, 'index'])->name('rewards');
            Route::get('mesa', [CustomerJoinController::class, 'index'])->name('join');
            Route::post('mesa/qr', [CustomerJoinController::class, 'createLink'])
                ->middleware('throttle:10,1')->name('link.create');
            Route::post('mesa/qr/cancelar', [CustomerJoinController::class, 'cancelLink'])->name('link.cancel');
            Route::get('mesa/{code}', [CustomerJoinController::class, 'show'])->name('table');
            Route::post('mesa/{code}/pedido', [CustomerJoinController::class, 'order'])->name('order');
            Route::post('mesa/{code}/participantes/{customer}/aprovar', [CustomerJoinController::class, 'approve'])
                ->whereNumber('customer')->name('join-requests.approve');
            Route::post('mesa/{code}/participantes/{customer}/recusar', [CustomerJoinController::class, 'reject'])
                ->whereNumber('customer')->name('join-requests.reject');
            Route::post('mesa/{code}/participantes/{customer}/titular', [CustomerJoinController::class, 'transfer'])
                ->whereNumber('customer')->name('responsible.transfer');
            Route::post('mesa/{code}/pedir-novamente', [CustomerJoinController::class, 'requestAgain'])->name('join-requests.retry');
            Route::post('mesa/{code}/sair', [CustomerJoinController::class, 'leave'])->name('leave');
            Route::post('mesa/{code}/chat', [CustomerJoinController::class, 'sendMessage'])
                ->middleware('throttle:30,1')->name('chat.send');
            Route::post('mesa/{code}/reacoes', [CustomerJoinController::class, 'react'])
                ->middleware('throttle:60,1')->name('chat.react');
            // Paying the table in the app (Stripe Checkout).
            Route::post('mesa/{code}/pagamentos', [CustomerPaymentController::class, 'store'])
                ->middleware('throttle:10,1')->name('payments.store');
            Route::get('pagamentos/{payment}/retorno', [CustomerPaymentController::class, 'back'])
                ->whereNumber('payment')->name('payments.return');
            Route::post('pagamentos/{payment}/cancelar', [CustomerPaymentController::class, 'cancel'])
                ->whereNumber('payment')->name('payments.cancel');
        });

    // Social: anyone signed in reacts to and comments on restaurant posts
    Route::put('publicacoes/{post}/reacao', [PostInteractionController::class, 'react'])
        ->middleware('throttle:60,1')
        ->name('posts.react');
    Route::delete('publicacoes/{post}/reacao', [PostInteractionController::class, 'unreact'])
        ->middleware('throttle:60,1')
        ->name('posts.unreact');
    Route::post('publicacoes/{post}/comentarios', [PostInteractionController::class, 'storeComment'])
        ->middleware('throttle:10,1')
        ->name('posts.comments.store');
    Route::delete('comentarios/{comment}', [PostInteractionController::class, 'destroyComment'])
        ->name('comments.destroy');
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

        // Customer incentives the restaurant offers (and pays for), each on or off.
        Route::get('incentivos', [IncentiveController::class, 'index'])->name('incentives.index');
        // Declared before incentivos/{incentive}, which would otherwise catch it.
        Route::put('incentivos/fidelidade/etapas', [IncentiveController::class, 'updateLoyaltyTiers'])->name('incentives.loyalty.tiers');
        Route::put('incentivos/{incentive}', [IncentiveController::class, 'update'])->name('incentives.update');

        // Financial profile: the Stripe account customers' payments go to.
        Route::get('financeiro', [FinanceController::class, 'show'])->name('finance.show');
        Route::post('financeiro/stripe', [FinanceController::class, 'connect'])
            ->middleware('throttle:10,1')
            ->name('finance.connect');
        Route::get('financeiro/stripe/atualizar', [FinanceController::class, 'refresh'])->name('finance.refresh');
        Route::get('financeiro/stripe/retorno', [FinanceController::class, 'back'])->name('finance.return');
        Route::resource('cupons', CouponController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('coupons')
            ->parameters(['cupons' => 'coupon']);

        // Venue settings: opening hours, photos, logo and banner
        Route::get('estabelecimento', [EstablishmentController::class, 'edit'])->name('establishment.edit');
        Route::put('estabelecimento/perfil', [EstablishmentController::class, 'updateProfile'])->name('establishment.profile.update');
        // Social posts on the public profile
        Route::post('publicacoes', [PostController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('posts.store');
        Route::delete('publicacoes/{post}', [PostController::class, 'destroy'])->name('posts.destroy');
        // "Hoje tem!": stories that disappear after 24 hours (uploads are heavy: a tighter limit)
        Route::post('hoje-tem', [StoryController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('stories.store');
        Route::delete('hoje-tem/{story}', [StoryController::class, 'destroy'])->name('stories.destroy');
        Route::put('estabelecimento/horarios', [EstablishmentController::class, 'updateHours'])->name('establishment.hours.update');
        Route::post('estabelecimento/logo', [EstablishmentController::class, 'updateLogo'])->name('establishment.logo.update');
        Route::delete('estabelecimento/logo', [EstablishmentController::class, 'destroyLogo'])->name('establishment.logo.destroy');
        Route::post('estabelecimento/banner', [EstablishmentController::class, 'updateBanner'])->name('establishment.banner.update');
        Route::delete('estabelecimento/banner', [EstablishmentController::class, 'destroyBanner'])->name('establishment.banner.destroy');
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
        Route::resource('restaurantes', AdminRestaurantController::class)
            ->only(['index', 'update'])
            ->names('restaurants')
            ->parameters(['restaurantes' => 'restaurant']);
        Route::get('repasses', [AdminPayoutController::class, 'index'])->name('payouts.index');
        Route::post('repasses', [AdminPayoutController::class, 'store'])->name('payouts.store');
        Route::resource('cupons', AdminCouponController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('coupons')
            ->parameters(['cupons' => 'coupon']);
        Route::resource('solicitacoes', AdminAdmissionRequestController::class)
            ->only(['index', 'update'])
            ->names('admission-requests')
            ->parameters(['solicitacoes' => 'admissionRequest']);
    });

require __DIR__.'/settings.php';

// Any other URL: a 404 that goes through the "web" middleware (session, signed-in
// user), so the error page shows inside the app with the user's navigation.
// Must stay the last route.
Route::fallback(fn () => abort(404));
