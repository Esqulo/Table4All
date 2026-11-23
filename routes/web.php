<?php

use App\Enums\StaffFeature;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdmissionRequestController as AdminAdmissionRequestController;
use App\Http\Controllers\Admin\Auth\InvitationController as AdminInvitationController;
use App\Http\Controllers\Admin\Auth\LoginController as AdminLoginController;
use App\Http\Controllers\Admin\Auth\TwoFactorSetupController as AdminTwoFactorSetupController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Admin\FeeTierController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\PayoutController as AdminPayoutController;
use App\Http\Controllers\Admin\RestaurantController as AdminRestaurantController;
use App\Http\Controllers\Admin\UserController as AdminAccountController;
use App\Http\Controllers\AdmissionRequestController;
use App\Http\Controllers\Customer\JoinController as CustomerJoinController;
use App\Http\Controllers\Customer\PaymentController as CustomerPaymentController;
use App\Http\Controllers\Customer\RewardController as CustomerRewardController;
use App\Http\Controllers\DiscoverController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Controllers\PostInteractionController;
use App\Http\Controllers\Restaurant\CallController;
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
use App\Http\Middleware\EnsureFeatureAccess;
use App\Http\Middleware\EnsureNotStaff;
use App\Http\Middleware\EnsureRestaurant;
use App\Http\Middleware\EnsureRestaurantOrWaiter;
use App\Http\Middleware\ThrottleTableCodes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::inertia('/contato', 'contact', [
    'email' => config('app.contact_email'),
])->name('contact');
Route::post('/contato', [AdmissionRequestController::class, 'store'])
    ->middleware('throttle:5,10')
    ->name('contact.store');
// Sales page shown in person to venue owners; reached only by its direct link.
Route::inertia('/parceiro', 'partner')->name('partner');
Route::get('/privacidade', [LegalController::class, 'privacy'])->name('privacy');
Route::get('/termos', [LegalController::class, 'terms'])->name('terms');

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
Route::post('/mercadopago/webhook', MercadoPagoWebhookController::class)->name('mercadopago.webhook');

Route::get('/mesa', [TableJoinController::class, 'index'])->name('table.join');
// Wrong table codes are limited (ThrottleTableCodes): they are short enough to guess.
Route::get('/mesa/{code}', [TableJoinController::class, 'show'])
    ->middleware(ThrottleTableCodes::class)
    ->name('table.show');

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
    // Finding restaurants: search, filters (how full, price, open now) and closest/best rated first.
    Route::get('descobrir', [DiscoverController::class, 'index'])->name('social.discover');

    Route::prefix('customer')
        ->name('customer.')
        // ThrottleTableCodes limits wrong codes on the mesa/{code} routes (only those carry one).
        ->middleware([EnsureNotStaff::class, ThrottleTableCodes::class])
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
            Route::post('mesa/{code}/chamadas', [CustomerJoinController::class, 'call'])
                ->middleware('throttle:10,1')->name('calls.store');
            Route::delete('mesa/{code}/chamadas/{type}', [CustomerJoinController::class, 'cancelCall'])
                ->whereIn('type', ['waiter', 'bill'])->name('calls.cancel');
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

// The restaurant's back office. The owner reaches everything; each staff member (waiter
// or manager) only the features granted to them, read-only or with writing
// (EnsureFeatureAccess: pages need read access, changes and create/edit forms need write).
Route::middleware(['auth', 'verified', EnsureRestaurantOrWaiter::class])
    ->prefix('restaurant')
    ->name('restaurant.')
    ->group(function () {
        $feature = fn (StaffFeature|string $f): string => EnsureFeatureAccess::class.':'.($f instanceof StaffFeature ? $f->value : $f);

        Route::middleware($feature(StaffFeature::TABLES))->group(function () {
            Route::resource('mesas', TableController::class)
                ->names('tables')
                ->parameters(['mesas' => 'table']);
            Route::post('mesas/{table}/pagamentos', [TableController::class, 'addPayment'])
                ->name('tables.add-payment');
            Route::patch('mesas/{table}/fechar', [TableController::class, 'close'])
                ->name('tables.close');
            // A customer changes tables (some items move) or two groups join (one table goes).
            Route::post('mesas/{table}/transferir', [TableController::class, 'transferItems'])
                ->name('tables.transfer');
            Route::post('mesas/{table}/juntar', [TableController::class, 'merge'])
                ->withTrashed()
                ->name('tables.merge');
            // Staff take the service fee off a table's bill (or put it back).
            Route::patch('mesas/{table}/taxa-servico', [TableController::class, 'waiveServiceFee'])
                ->name('tables.service-fee');
            // A team member takes a free table or lets go of their own.
            Route::patch('mesas/{table}/assumir', [TableController::class, 'claim'])
                ->name('tables.claim');
            Route::patch('mesas/{table}/liberar', [TableController::class, 'release'])
                ->name('tables.release');
        });
        // The owner or a manager gives a table to someone on the team (team management).
        Route::put('mesas/{table}/garcom', [TableController::class, 'assignWaiter'])
            ->middleware($feature(StaffFeature::STAFF))
            ->name('tables.assign');

        // Tables calling the waiter or asking for the bill.
        Route::get('chamadas', [CallController::class, 'index'])
            ->middleware($feature(StaffFeature::CALLS))
            ->name('calls.index');
        Route::patch('chamadas/{call}/atendida', [CallController::class, 'answer'])
            ->middleware($feature(StaffFeature::CALLS))
            ->name('calls.answer');

        Route::get('entregas', [DeliveryController::class, 'index'])
            ->middleware($feature(StaffFeature::DELIVERY))
            ->name('delivery.index');
        Route::patch('queue-items/{queueItem}/entregue', [QueueItemController::class, 'markDelivered'])
            ->middleware($feature(StaffFeature::DELIVERY))
            ->name('queue-items.mark-delivered');
        // The kitchen marks items ready on its queue; the delivery board can too.
        Route::patch('queue-items/{queueItem}/pronto', [QueueItemController::class, 'markDone'])
            ->middleware($feature('queues|delivery'))
            ->name('queue-items.mark-done');

        // Staff who only mark products sold out (availability) see the list too, read-only.
        Route::get('produtos', [ProductController::class, 'index'])
            ->middleware($feature('products|availability'))
            ->name('products.index');
        Route::patch('produtos/{product}/esgotado', [ProductController::class, 'soldOut'])
            ->middleware($feature('products|availability'))
            ->name('products.sold-out');

        Route::middleware($feature(StaffFeature::PRODUCTS))->group(function () {
            Route::post('produtos/{product}/foto', [ProductController::class, 'updatePicture'])
                ->name('products.picture.update');
            Route::resource('produtos', ProductController::class)
                ->except(['show', 'index'])
                ->names('products')
                ->parameters(['produtos' => 'product']);
        });

        Route::resource('filas', QueueController::class)
            ->middleware($feature(StaffFeature::QUEUES))
            ->names('queues')
            ->parameters(['filas' => 'queue']);

        Route::middleware($feature(StaffFeature::MENUS))->group(function () {
            Route::resource('cardapio', MenuController::class)
                ->only(['index', 'create', 'store'])
                ->names('menus')
                ->parameters(['cardapio' => 'menu']);
            Route::get('cardapio/{menu}/imprimir', [MenuController::class, 'printMenu'])
                ->name('menus.print');
            Route::put('cardapio/{menu}/layout', [MenuController::class, 'updateLayout'])
                ->name('menus.layout.update');
        });

        Route::resource('promocoes', SaleController::class)
            ->middleware($feature(StaffFeature::SALES))
            ->only(['index', 'create', 'store', 'edit', 'update', 'destroy'])
            ->names('sales')
            ->parameters(['promocoes' => 'sale']);

        Route::resource('cupons', CouponController::class)
            ->middleware($feature(StaffFeature::COUPONS))
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('coupons')
            ->parameters(['cupons' => 'coupon']);

        // Customer incentives the restaurant offers (and pays for), each on or off.
        Route::middleware($feature(StaffFeature::INCENTIVES))->group(function () {
            Route::get('incentivos', [IncentiveController::class, 'index'])->name('incentives.index');
            // Declared before incentivos/{incentive}, which would otherwise catch it.
            Route::put('incentivos/fidelidade/etapas', [IncentiveController::class, 'updateLoyaltyTiers'])->name('incentives.loyalty.tiers');
            Route::put('incentivos/{incentive}', [IncentiveController::class, 'update'])->name('incentives.update');
        });

        // Read-only for staff (StaffFeature::staffCeiling()).
        Route::get('painel', [DashboardController::class, 'index'])
            ->middleware($feature(StaffFeature::DASHBOARD))
            ->name('dashboards.index');
        Route::get('financeiro', [FinanceController::class, 'show'])
            ->middleware($feature(StaffFeature::FINANCE))
            ->name('finance.show');
        Route::get('estabelecimento', [EstablishmentController::class, 'edit'])
            ->middleware($feature(StaffFeature::ESTABLISHMENT))
            ->name('establishment.edit');

        // The team: the owner, and managers for the waiters (WaiterController checks who).
        // cancelInvitation must be declared before the resource so it's matched first.
        Route::middleware($feature(StaffFeature::STAFF))->group(function () {
            Route::delete('garcons/convites/{invitation}', [WaiterController::class, 'cancelInvitation'])
                ->name('waiters.cancel-invitation');
            Route::resource('garcons', WaiterController::class)
                ->only(['index', 'store', 'update', 'destroy'])
                ->names('waiters')
                ->parameters(['garcons' => 'waiter']);
        });
    });

// Owner only: the venue itself, its finances and posting in its name.
Route::middleware(['auth', 'verified', EnsureRestaurant::class])
    ->prefix('restaurant')
    ->name('restaurant.')
    ->group(function () {
        // Financial profile: the Stripe account customers' payments go to.
        Route::post('financeiro/stripe', [FinanceController::class, 'connect'])
            ->middleware('throttle:10,1')
            ->name('finance.connect');
        Route::get('financeiro/stripe/atualizar', [FinanceController::class, 'refresh'])->name('finance.refresh');
        Route::get('financeiro/stripe/retorno', [FinanceController::class, 'back'])->name('finance.return');
        // Or a Mercado Pago account, linked to the platform's app (OAuth).
        Route::post('financeiro/mercadopago', [FinanceController::class, 'connectMercadoPago'])
            ->middleware('throttle:10,1')
            ->name('finance.mercadopago.connect');
        Route::get('financeiro/mercadopago/retorno', [FinanceController::class, 'mercadoPagoBack'])
            ->middleware('throttle:10,1')
            ->name('finance.mercadopago.return');
        Route::delete('financeiro/mercadopago', [FinanceController::class, 'disconnectMercadoPago'])
            ->name('finance.mercadopago.disconnect');
        // Which linked account new payments go to.
        Route::put('financeiro/provedor', [FinanceController::class, 'chooseProvider'])->name('finance.provider');

        // Venue settings: public profile, opening hours, photos, logo and banner
        Route::put('estabelecimento/perfil', [EstablishmentController::class, 'updateProfile'])->name('establishment.profile.update');
        Route::put('estabelecimento/horarios', [EstablishmentController::class, 'updateHours'])->name('establishment.hours.update');
        Route::put('estabelecimento/atendimento', [EstablishmentController::class, 'updateService'])->name('establishment.service.update');
        Route::put('estabelecimento/taxa-servico', [EstablishmentController::class, 'updateServiceFee'])->name('establishment.service-fee.update');
        Route::post('estabelecimento/logo', [EstablishmentController::class, 'updateLogo'])->name('establishment.logo.update');
        Route::delete('estabelecimento/logo', [EstablishmentController::class, 'destroyLogo'])->name('establishment.logo.destroy');
        Route::post('estabelecimento/banner', [EstablishmentController::class, 'updateBanner'])->name('establishment.banner.update');
        Route::delete('estabelecimento/banner', [EstablishmentController::class, 'destroyBanner'])->name('establishment.banner.destroy');
        Route::post('estabelecimento/fotos', [EstablishmentController::class, 'storePhotos'])->name('establishment.photos.store');
        Route::put('estabelecimento/fotos/ordem', [EstablishmentController::class, 'reorderPhotos'])->name('establishment.photos.reorder');
        Route::delete('estabelecimento/fotos/{photo}', [EstablishmentController::class, 'destroyPhoto'])->name('establishment.photos.destroy');

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
    });

// The admins' own sign-in, apart from everyone else's: password, then the authenticator code.
Route::middleware('guest')->prefix('admin')->name('admin.')->group(function () {
    Route::get('entrar', [AdminLoginController::class, 'show'])->name('login');
    Route::post('entrar', [AdminLoginController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
    Route::get('entrar/codigo', [AdminLoginController::class, 'showCode'])->name('login.code');
    Route::post('entrar/codigo', [AdminLoginController::class, 'verifyCode'])->middleware('throttle:20,1')->name('login.code.store');
    // Accepting an invitation to become an admin.
    Route::get('convite/{token}', [AdminInvitationController::class, 'show'])->name('invitation.show');
    Route::post('convite/{token}', [AdminInvitationController::class, 'store'])->middleware('throttle:10,1')->name('invitation.accept');
});

// Setting up the authenticator app: the only admin screen reached before it's done.
Route::middleware(['auth', 'verified', EnsureAdmin::class.':setup'])
    ->prefix('admin/autenticador')
    ->name('admin.two-factor.')
    ->group(function () {
        Route::get('/', [AdminTwoFactorSetupController::class, 'show'])->name('setup');
        Route::post('/', [AdminTwoFactorSetupController::class, 'store'])->middleware('throttle:10,1')->name('confirm');
        Route::get('codigos', [AdminTwoFactorSetupController::class, 'recoveryCodes'])->name('recovery-codes');
    });

Route::middleware(['auth', 'verified', EnsureAdmin::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        // The platform at a glance: people (only counted), restaurants, orders and payments.
        Route::get('painel', [OverviewController::class, 'index'])->name('overview');
        Route::redirect('/', '/admin/painel');

        Route::resource('categorias', CategoryController::class)
            ->only(['index', 'store', 'destroy'])
            ->names('categories')
            ->parameters(['categorias' => 'category']);
        Route::resource('restaurantes', AdminRestaurantController::class)
            ->only(['index', 'store', 'show', 'update'])
            ->names('restaurants')
            ->parameters(['restaurantes' => 'restaurant']);
        // Every account on the platform; switching one off (a restaurant takes its team along).
        Route::get('usuarios', [AdminAccountController::class, 'index'])->name('users.index');
        Route::patch('usuarios/{user}/desativar', [AdminAccountController::class, 'deactivate'])->name('users.deactivate');
        Route::patch('usuarios/{user}/reativar', [AdminAccountController::class, 'reactivate'])->name('users.reactivate');
        // Commercial plans: the platform's commission on online payments.
        Route::resource('planos', FeeTierController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('tiers')
            ->parameters(['planos' => 'feeTier']);
        Route::get('repasses', [AdminPayoutController::class, 'index'])->name('payouts.index');
        Route::post('repasses', [AdminPayoutController::class, 'store'])->name('payouts.store');
        Route::resource('cupons', AdminCouponController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('coupons')
            ->parameters(['cupons' => 'coupon']);
        // The platform's admins and invitations for new ones.
        Route::get('administradores', [AdminUserController::class, 'index'])->name('admins.index');
        Route::post('administradores', [AdminUserController::class, 'store'])->middleware('throttle:10,1')->name('admins.store');
        Route::delete('administradores/convites/{invitation}', [AdminUserController::class, 'destroyInvitation'])->name('admins.invitations.destroy');
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
