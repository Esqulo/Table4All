<?php

namespace App\Http\Controllers\Customer;

use App\Enums\AccountType;
use App\Enums\OnlinePaymentStatus;
use App\Enums\PaymentMethod;
use App\Enums\QueueItemStatus;
use App\Enums\TableCustomerStatus;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\OnlinePayment;
use App\Models\Product;
use App\Models\QueueItem;
use App\Models\RestaurantTable;
use App\Models\Sale;
use App\Models\TableCall;
use App\Models\User;
use App\Support\CustomerLink;
use App\Support\Idempotency;
use App\Support\Payments\CheckoutRefused;
use App\Support\Payments\TableCheckout;
use App\Support\ServiceFee;
use App\Support\TableChat;
use App\Support\TableMembership;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

class JoinController extends Controller
{
    public function index(Request $request): Response
    {
        // Every table the customer joined stays here until the restaurant closes it.
        $tables = DB::table('restaurant_table_customers')
            ->where('restaurant_table_customers.user_id', $request->user()->id)
            ->join('restaurant_tables', 'restaurant_tables.id', '=', 'restaurant_table_customers.restaurant_table_id')
            ->whereNull('restaurant_tables.closed_at')
            ->whereNull('restaurant_tables.deleted_at')
            ->where('restaurant_table_customers.status', '!=', TableCustomerStatus::REJECTED->value)
            ->join('users as restaurants', 'restaurants.id', '=', 'restaurant_tables.user_id')
            ->orderByDesc('restaurant_table_customers.id')
            ->get([
                'restaurant_tables.access_code as code',
                'restaurant_tables.title',
                'restaurants.name as restaurant',
                'restaurant_table_customers.status',
            ]);

        return Inertia::render('customer/join', [
            'tables' => $tables,
            'link' => CustomerLink::latestFor($request->user()->id),
        ]);
    }

    /**
     * A one-time QR (valid one minute) the customer shows to staff opening a
     * table, so the new table is linked to them.
     */
    public function createLink(Request $request): RedirectResponse
    {
        CustomerLink::issue($request->user()->id);

        return redirect()->route('customer.join');
    }

    /** The customer withdraws their QR before anyone uses it. */
    public function cancelLink(Request $request): RedirectResponse
    {
        CustomerLink::cancel($request->user()->id);

        return redirect()->route('customer.join');
    }

    public function show(Request $request, string $code): Response|RedirectResponse
    {
        $table = RestaurantTable::where('access_code', $code)
            ->whereNull('closed_at')
            ->whereNull('deleted_at')
            ->first(['id', 'user_id', 'title', 'responsible_user_id', ...ServiceFee::COLUMNS, 'service_fee_waived']);

        if (! $table) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'join_table.invalid_code']);

            return redirect()->route('customer.join');
        }

        $user = $request->user();
        $status = $this->admit($user->id, $table);

        if ($status !== TableCustomerStatus::APPROVED) {
            $rejections = $status === TableCustomerStatus::REJECTED
                ? (int) DB::table('restaurant_table_customers')
                    ->where('restaurant_table_id', $table->id)
                    ->where('user_id', $user->id)
                    ->value('rejections')
                : 0;

            // Polled by the waiting screen; once approved it receives the table page.
            return Inertia::render('customer/waiting', [
                'code' => $code,
                'table' => $table->title,
                'restaurant' => User::whereKey($table->user_id)->value('name'),
                'status' => $status->value,
                'attemptsLeft' => max(0, TableCustomerStatus::MAX_REJECTIONS - $rejections),
            ]);
        }

        $isResponsible = $table->responsible_user_id === $user->id;

        // Every prop is lazy: a partial reload (the chat polls every few seconds)
        // runs only the queries for what it asks for.
        $menu = function () use ($table) {
            $activeSales = Sale::activePriceMap($table->user_id);

            return Product::where('user_id', $table->user_id)
                ->with('category:id,name')
                ->orderBy('name')
                ->get(['id', 'category_id', 'name', 'description', 'picture', 'price', 'price_type', 'queue_id', 'sold_out_at'])
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'category_id' => $p->category_id,
                    'category_name' => $p->category?->name,
                    'name' => $p->name,
                    'description' => $p->description,
                    'price' => (float) ($activeSales[$p->id] ?? $p->price),
                    'price_type' => $p->price_type,
                    'has_queue' => $p->queue_id !== null,
                    'sold_out' => $p->sold_out_at !== null,
                    'picture_url' => $p->picture ? Storage::disk('public')->url($p->picture) : null,
                ])
                ->all();
        };

        $products = fn () => DB::table('restaurant_table_product')
            ->where('restaurant_table_id', $table->id)
            ->join('products', 'products.id', '=', 'restaurant_table_product.product_id')
            ->leftJoin('users', 'users.id', '=', 'restaurant_table_product.ordered_by_user_id')
            ->select(
                'products.id',
                'products.name',
                'products.description',
                'products.picture',
                'products.price_type',
                'restaurant_table_product.price',
                'restaurant_table_product.quantity',
                'users.name as ordered_by_name',
                'users.email as ordered_by_email',
            )
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'description' => $row->description,
                'price' => (float) $row->price,
                'price_type' => $row->price_type,
                'quantity' => (int) $row->quantity,
                'picture_url' => $row->picture ? Storage::disk('public')->url($row->picture) : null,
                'ordered_by' => $row->ordered_by_name ?? $row->ordered_by_email ?? null,
            ])
            ->all();

        $preparing = fn () => QueueItem::where('restaurant_table_id', $table->id)
            ->whereIn('status', QueueItemStatus::open())
            ->with(['product:id,name', 'orderedBy:id,name,email'])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($item) => [
                'name' => $item->product->name,
                'price' => (float) $item->price,
                'quantity' => (int) $item->quantity,
                'notes' => $item->notes,
                'has_queue' => $item->queue_id !== null,
                'ordered_by' => $item->orderedBy !== null ? ($item->orderedBy->name ?? $item->orderedBy->email) : null,
            ]);

        // The bill is the products plus the service fee, shown apart from them.
        $money = null;
        $totals = function () use ($table, &$money): array {
            if ($money === null) {
                $products = ServiceFee::productCents($table->id);
                $fee = ServiceFee::forTable($table, $products);
                $money = [
                    'subtotal' => $products / 100,
                    'serviceFee' => $fee / 100,
                    'total' => ($products + $fee) / 100,
                    'paid' => (float) $table->payments()->sum('amount'),
                ];
            }

            return $money;
        };
        $feeTerms = ServiceFee::terms($table);

        return Inertia::render('customer/show', [
            'code' => $code,
            // Where the customer is: shown at the top of the screen.
            'tableTitle' => $table->title,
            'restaurant' => User::whereKey($table->user_id)->value('name'),
            'menu' => $menu,
            'products' => $products,
            'preparing' => $preparing,
            'subtotal' => fn () => $totals()['subtotal'],
            'serviceFee' => fn () => $totals()['serviceFee'],
            // How the fee is worked out ("10%"), when the table has one and it wasn't waived.
            'serviceFeeTerms' => fn () => $feeTerms === null || $feeTerms['waived'] ? null : $feeTerms,
            'total' => fn () => $totals()['total'],
            'paid' => fn () => $totals()['paid'],
            'remaining' => fn () => max(0.0, $totals()['total'] - $totals()['paid']),
            'isResponsible' => $isResponsible,
            'members' => fn () => $this->members($table->id, $table->responsible_user_id, $user->id),
            'joinRequests' => fn () => $isResponsible ? $this->pendingRequests($table->id) : [],
            'chat' => fn () => TableChat::feed($table->id, $user->id),
            'chatReactions' => TableChat::REACTIONS,
            'chatMaxLength' => TableChat::MAX_LENGTH,
            // Paying in the app: only once the restaurant's Stripe account is active.
            'onlinePayments' => fn () => [
                'enabled' => User::whereKey($table->user_id)
                    ->whereNotNull('stripe_account_id')
                    ->where('stripe_charges_enabled', true)
                    ->exists(),
                'min' => ((int) config('payments.min_charge_cents')) / 100,
            ],
            'payments' => fn () => $this->payments($table->id),
            // The table's open calls to staff: the buttons show they're waiting.
            'calls' => fn () => TableCall::where('restaurant_table_id', $table->id)
                ->whereNull('resolved_at')
                ->pluck('created_at', 'type')
                ->map(fn ($at) => $at?->toIso8601String())
                ->all(),
            'myCheckout' => fn () => $this->openCheckout($table->id, $user->id),
            // Asked for by the payment form (partial reload with ?coupon=&amount=).
            'couponQuote' => Inertia::optional(fn () => $this->couponQuote($request, $table)),
        ]);
    }

    /**
     * Every payment on the bill, and who made it, plus in-app payments still
     * being confirmed: everyone at the table sees who paid what.
     *
     * @return array<int, array{method: string, amount: float, payer: string|null, by_staff: bool, status: string, created_at: string|null}>
     */
    private function payments(int $tableId): array
    {
        $recorded = DB::table('table_payments')
            ->where('table_payments.restaurant_table_id', $tableId)
            ->leftJoin('users', 'users.id', '=', 'table_payments.registered_by_id')
            ->orderBy('table_payments.id')
            ->get([
                'table_payments.method',
                'table_payments.amount',
                'table_payments.registered_by_type',
                'table_payments.created_at',
                'users.name',
                'users.email',
            ])
            ->map(function ($row) {
                $byCustomer = $row->registered_by_type === AccountType::CUSTOMER->value;

                return [
                    'method' => (string) $row->method,
                    'amount' => (float) $row->amount,
                    // Staff are not named to customers: "paid at the counter".
                    'payer' => $byCustomer ? ($row->name ?: $row->email) : null,
                    'by_staff' => ! $byCustomer,
                    'status' => OnlinePaymentStatus::PAID->value,
                    'created_at' => $row->created_at !== null ? Carbon::parse($row->created_at)->toIso8601String() : null,
                ];
            });

        $confirming = DB::table('online_payments')
            ->where('online_payments.restaurant_table_id', $tableId)
            ->where('online_payments.status', OnlinePaymentStatus::PROCESSING->value)
            ->leftJoin('users', 'users.id', '=', 'online_payments.user_id')
            ->orderBy('online_payments.id')
            ->get(['online_payments.amount_cents', 'online_payments.created_at', 'users.name', 'users.email'])
            ->map(fn ($row) => [
                'method' => PaymentMethod::ONLINE->value,
                'amount' => $row->amount_cents / 100,
                'payer' => $row->name ?: $row->email,
                'by_staff' => false,
                'status' => OnlinePaymentStatus::PROCESSING->value,
                'created_at' => $row->created_at !== null ? Carbon::parse($row->created_at)->toIso8601String() : null,
            ]);

        return $recorded->concat($confirming)->values()->all();
    }

    /**
     * The checkout this customer left open on Stripe, to resume or drop it.
     *
     * @return array{id: int, amount: float, charge: float, url: string}|null
     */
    private function openCheckout(int $tableId, int $userId): ?array
    {
        $payment = OnlinePayment::where('restaurant_table_id', $tableId)
            ->where('user_id', $userId)
            ->where('status', OnlinePaymentStatus::PENDING->value)
            ->whereNotNull('checkout_url')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first(['id', 'amount_cents', 'charge_cents', 'checkout_url']);

        return $payment === null ? null : [
            'id' => $payment->id,
            'amount' => $payment->amount_cents / 100,
            'charge' => $payment->charge_cents / 100,
            'url' => (string) $payment->checkout_url,
        ];
    }

    /**
     * What the coupon typed in the payment form takes off the amount, or why it doesn't apply.
     *
     * @return array{code: string, valid: bool, error: string|null, discount: float, charge: float}
     */
    private function couponQuote(Request $request, RestaurantTable $table): array
    {
        $code = Coupon::normalizeCode((string) $request->query('coupon', ''));
        $amountCents = (int) round(100 * (float) $request->query('amount', '0'));

        try {
            $quote = app(TableCheckout::class)->quote($request->user(), $table, $amountCents, $code);
        } catch (CheckoutRefused $refused) {
            return ['code' => $code, 'valid' => false, 'error' => $refused->translated(), 'discount' => 0.0, 'charge' => $amountCents / 100];
        }

        return [
            'code' => $code,
            'valid' => true,
            'error' => null,
            'discount' => $quote['discount_cents'] / 100,
            'charge' => $quote['charge_cents'] / 100,
        ];
    }

    public function order(Request $request, string $code): RedirectResponse
    {
        $table = RestaurantTable::where('access_code', $code)
            ->whereNull('closed_at')
            ->whereNull('deleted_at')
            ->firstOrFail(['id', 'user_id', 'responsible_user_id']);

        // Only customers the responsible let in order on this table's bill.
        if ($this->admit($request->user()->id, $table) !== TableCustomerStatus::APPROVED) {
            return redirect()->route('customer.table', $code);
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.notes' => ['nullable', 'string', 'max:'.QueueItem::NOTES_MAX],
            'request_key' => ['nullable', 'string', 'max:64'],
        ]);

        $productIds = array_column($validated['items'], 'product_id');
        $products = Product::where('user_id', $table->user_id)
            ->whereIn('id', $productIds)
            ->get(['id', 'name', 'queue_id', 'price', 'sold_out_at'])
            ->keyBy('id');

        // Something sold out while the customer was choosing: say what, and order nothing,
        // so they can change the cart instead of finding out from the waiter.
        $soldOut = $products->filter(fn (Product $p): bool => $p->isSoldOut());

        if ($soldOut->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => __('join_table.error_sold_out', ['products' => $soldOut->pluck('name')->implode(', ')]),
            ]);
        }
        $activeSales = Sale::activePriceMap($table->user_id);
        $userId = $request->user()->id;
        $now = now();

        // All customer orders go through queue_items (queue_id is null for non-kitchen products).
        // Items reach restaurant_table_product only after a waiter marks them as delivered.
        $rows = [];
        foreach ($validated['items'] as $item) {
            $product = $products->get($item['product_id']);

            if (! $product) {
                continue;
            }

            $rows[] = [
                'restaurant_table_id' => $table->id,
                'product_id' => $product->id,
                'queue_id' => $product->queue_id ?: null,
                'ordered_by_user_id' => $userId,
                'quantity' => $item['quantity'],
                'notes' => QueueItem::cleanNotes($item['notes'] ?? null),
                'price' => (float) ($activeSales[$product->id] ?? $product->price),
                'status' => QueueItemStatus::PENDING->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Every item was deleted from the menu or belongs to another restaurant:
        // say so instead of confirming an order the kitchen will never see.
        if ($rows === []) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'join_table.order_invalid']);

            return redirect()->route('customer.table', $code);
        }

        // One INSERT for the whole cart; the request key makes a retried submit
        // (slow network, double tap) a no-op instead of a duplicated order.
        Idempotency::once(
            $request,
            'customer-order:'.$table->id,
            fn () => QueueItem::insert($rows),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'join_table.order_success']);

        return redirect()->route('customer.table', $code);
    }

    /** A short message to everyone at the table. */
    public function sendMessage(Request $request, string $code): RedirectResponse
    {
        $table = $this->tableForMember($request, $code);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:'.TableChat::MAX_LENGTH],
            'request_key' => ['nullable', 'string', 'max:64'],
        ]);

        // A resent message (slow network, double tap) is posted once.
        Idempotency::once(
            $request,
            'table-chat:'.$table->id,
            fn () => TableChat::send($table->id, $request->user()->id, $validated['body']),
        );

        return redirect()->route('customer.table', $code);
    }

    /**
     * Calls the waiter or asks for the bill. A second tap while the call is open does
     * nothing (one open call per table and type), so it is safe to retry.
     */
    public function call(Request $request, string $code): RedirectResponse
    {
        $table = $this->tableForMember($request, $code);

        $validated = $request->validate([
            'type' => ['required', Rule::in(TableCall::TYPES)],
        ]);

        TableCall::open($table, $validated['type'], $request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'join_table.call_sent_'.$validated['type']]);

        return redirect()->route('customer.table', $code);
    }

    /** Anyone at the table takes the call back (they no longer need the waiter). */
    public function cancelCall(Request $request, string $code, string $type): RedirectResponse
    {
        $table = $this->tableForMember($request, $code);
        abort_unless(in_array($type, TableCall::TYPES, true), 404);

        TableCall::resolve($table->id, TableCall::CANCELLED, $request->user()->id, $type);

        return redirect()->route('customer.table', $code);
    }

    /** An emoji that floats up on the screens of everyone at the table. */
    public function react(Request $request, string $code): RedirectResponse
    {
        $table = $this->tableForMember($request, $code);

        $validated = $request->validate([
            'emoji' => ['required', 'string', Rule::in(TableChat::REACTIONS)],
        ]);

        TableChat::react($table->id, $request->user()->id, $validated['emoji']);

        return redirect()->route('customer.table', $code);
    }

    /** The open table behind $code, for a customer the responsible let in. */
    private function tableForMember(Request $request, string $code): RestaurantTable
    {
        $table = RestaurantTable::where('access_code', $code)
            ->whereNull('closed_at')
            ->whereNull('deleted_at')
            ->firstOrFail(['id', 'user_id', 'responsible_user_id']);

        abort_unless(TableMembership::isMember($table, $request->user()->id), 403);

        return $table;
    }

    /** The responsible lets a customer who asked into the table. */
    public function approve(Request $request, string $code, int $customer): RedirectResponse
    {
        return $this->decide($request, $code, $customer, TableCustomerStatus::APPROVED);
    }

    /** The responsible turns a customer's request down. */
    public function reject(Request $request, string $code, int $customer): RedirectResponse
    {
        return $this->decide($request, $code, $customer, TableCustomerStatus::REJECTED);
    }

    private function decide(Request $request, string $code, int $customer, TableCustomerStatus $decision): RedirectResponse
    {
        $table = RestaurantTable::where('access_code', $code)
            ->whereNull('closed_at')
            ->whereNull('deleted_at')
            ->firstOrFail(['id', 'responsible_user_id']);

        abort_unless($table->responsible_user_id === $request->user()->id, 403);

        // Only a pending request is decided: a retried or double-tapped answer
        // can't flip a decision already made.
        DB::table('restaurant_table_customers')
            ->where('restaurant_table_id', $table->id)
            ->where('user_id', $customer)
            ->where('status', TableCustomerStatus::PENDING->value)
            ->update([
                'status' => $decision->value,
                'updated_at' => now(),
                ...($decision === TableCustomerStatus::REJECTED ? ['rejections' => DB::raw('rejections + 1')] : []),
            ]);

        return redirect()->route('customer.table', $code);
    }

    /** A rejected customer asks again, while under the rejection limit. */
    public function requestAgain(Request $request, string $code): RedirectResponse
    {
        $table = $this->openTable($code);

        DB::table('restaurant_table_customers')
            ->where('restaurant_table_id', $table->id)
            ->where('user_id', $request->user()->id)
            ->where('status', TableCustomerStatus::REJECTED->value)
            ->where('rejections', '<', TableCustomerStatus::MAX_REJECTIONS)
            ->update(['status' => TableCustomerStatus::PENDING->value, 'updated_at' => now()]);

        return redirect()->route('customer.table', $code);
    }

    /** The responsible hands the table over to another customer at it. */
    public function transfer(Request $request, string $code, int $customer): RedirectResponse
    {
        $table = $this->openTable($code);
        $userId = $request->user()->id;

        DB::transaction(function () use ($table, $userId, $customer) {
            $responsible = DB::table('restaurant_tables')
                ->where('id', $table->id)
                ->lockForUpdate()
                ->value('responsible_user_id');

            abort_unless($responsible === $userId, 403);

            $isMember = DB::table('restaurant_table_customers')
                ->where('restaurant_table_id', $table->id)
                ->where('user_id', $customer)
                ->where('status', TableCustomerStatus::APPROVED->value)
                ->exists();

            abort_unless($isMember, 404);

            DB::table('restaurant_tables')
                ->where('id', $table->id)
                ->update(['responsible_user_id' => $customer]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'join_table.responsible_transferred']);

        return redirect()->route('customer.table', $code);
    }

    /**
     * The customer leaves the table. When the responsible leaves, the role passes
     * to whoever joined next; with nobody left, the next customer to arrive takes it.
     */
    public function leave(Request $request, string $code): RedirectResponse
    {
        $table = $this->openTable($code);
        $userId = $request->user()->id;

        DB::transaction(function () use ($table, $userId) {
            $responsible = DB::table('restaurant_tables')
                ->where('id', $table->id)
                ->lockForUpdate()
                ->value('responsible_user_id');

            // A rejected customer keeps their row, so leaving can't reset the limit.
            DB::table('restaurant_table_customers')
                ->where('restaurant_table_id', $table->id)
                ->where('user_id', $userId)
                ->where('status', '!=', TableCustomerStatus::REJECTED->value)
                ->delete();

            if ($responsible === $userId) {
                $next = DB::table('restaurant_table_customers')
                    ->where('restaurant_table_id', $table->id)
                    ->where('status', TableCustomerStatus::APPROVED->value)
                    ->orderBy('id')
                    ->value('user_id');

                DB::table('restaurant_tables')
                    ->where('id', $table->id)
                    ->update(['responsible_user_id' => $next]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'join_table.left_table']);

        return redirect()->route('customer.join');
    }

    private function openTable(string $code): RestaurantTable
    {
        return RestaurantTable::where('access_code', $code)
            ->whereNull('closed_at')
            ->whereNull('deleted_at')
            ->firstOrFail(['id', 'responsible_user_id']);
    }

    /**
     * The customer's place at the table, recording it on first visit: the first
     * customer becomes the responsible (approved), anyone after them asks to join.
     * The table then stays in their account until the restaurant closes it.
     */
    private function admit(int $userId, RestaurantTable $table): TableCustomerStatus
    {
        if ($table->responsible_user_id === $userId) {
            return TableCustomerStatus::APPROVED;
        }

        $now = now();

        if ($table->responsible_user_id === null) {
            // Conditional, so of two customers arriving together only one wins.
            $claimed = DB::table('restaurant_tables')
                ->where('id', $table->id)
                ->whereNull('responsible_user_id')
                ->update(['responsible_user_id' => $userId]);

            if ($claimed === 1) {
                $table->responsible_user_id = $userId;

                DB::table('restaurant_table_customers')->upsert(
                    [[
                        'restaurant_table_id' => $table->id,
                        'user_id' => $userId,
                        'status' => TableCustomerStatus::APPROVED->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]],
                    ['restaurant_table_id', 'user_id'],
                    ['status', 'updated_at'],
                );

                return TableCustomerStatus::APPROVED;
            }
        }

        $status = DB::table('restaurant_table_customers')
            ->where('restaurant_table_id', $table->id)
            ->where('user_id', $userId)
            ->value('status');

        if ($status !== null) {
            return TableCustomerStatus::from($status);
        }

        DB::table('restaurant_table_customers')->insertOrIgnore([
            'restaurant_table_id' => $table->id,
            'user_id' => $userId,
            'status' => TableCustomerStatus::PENDING->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return TableCustomerStatus::PENDING;
    }

    /**
     * Customers at the table, in the order they joined (the order the role passes on).
     *
     * @return array<int, array{id: int, name: string, is_responsible: bool, is_me: bool}>
     */
    private function members(int $tableId, ?int $responsibleId, int $viewerId): array
    {
        return DB::table('restaurant_table_customers')
            ->where('restaurant_table_customers.restaurant_table_id', $tableId)
            ->where('restaurant_table_customers.status', TableCustomerStatus::APPROVED->value)
            ->join('users', 'users.id', '=', 'restaurant_table_customers.user_id')
            ->orderBy('restaurant_table_customers.id')
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) ($row->name ?: $row->email),
                'is_responsible' => (int) $row->id === $responsibleId,
                'is_me' => (int) $row->id === $viewerId,
            ])
            ->all();
    }

    /**
     * Customers waiting for the responsible's answer, oldest first.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function pendingRequests(int $tableId): array
    {
        return DB::table('restaurant_table_customers')
            ->where('restaurant_table_customers.restaurant_table_id', $tableId)
            ->where('restaurant_table_customers.status', TableCustomerStatus::PENDING->value)
            ->join('users', 'users.id', '=', 'restaurant_table_customers.user_id')
            ->orderBy('restaurant_table_customers.id')
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => (string) ($row->name ?: $row->email)])
            ->all();
    }
}
