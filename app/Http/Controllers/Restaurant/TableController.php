<?php

namespace App\Http\Controllers\Restaurant;

use App\Enums\PaymentMethod;
use App\Enums\QueueItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\TableRequest;
use App\Models\Product;
use App\Models\QueueItem;
use App\Models\RestaurantTable;
use App\Models\Sale;
use App\Models\TableCall;
use App\Models\User;
use App\Support\CustomerLink;
use App\Support\Idempotency;
use App\Support\ServiceFee;
use App\Support\TableAssignment;
use App\Support\TableTransfer;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class TableController extends Controller
{
    /** What saving the order did (syncOrder). */
    private const SYNC_SAVED = 'saved';

    private const SYNC_CONFLICT = 'conflict';

    private const SYNC_SOLD_OUT = 'sold_out';

    public function index(Request $request): Response
    {
        // Totals come from the snapshotted order prices (never the current product
        // price) and are computed in SQL, so the board doesn't ship every line and
        // payment of every open table on each refresh.
        $orderTotal = DB::table('restaurant_table_product')
            ->whereColumn('restaurant_table_id', 'restaurant_tables.id')
            ->selectRaw('COALESCE(SUM(price * quantity), 0)');

        $tables = RestaurantTable::where('user_id', $request->user()->effectiveRestaurantId())
            ->whereNull('closed_at')
            ->select('restaurant_tables.*')
            ->selectSub($orderTotal, 'order_total')
            ->withSum('payments as paid_total', 'amount')
            ->withCount('products')
            ->with([
                'waiter:id,name',
                // Thumbnails only: the card shows at most five.
                'products' => fn ($q) => $q
                    ->select('products.id', 'products.name', 'products.picture')
                    ->limit(5),
            ])
            ->orderBy('title')
            ->get()
            ->each(function (RestaurantTable $table): void {
                $orderTotal = (float) $table->getAttribute('order_total');
                $table->setAttribute('order_total', $orderTotal);
                $table->setAttribute('paid_total', (float) ($table->getAttribute('paid_total') ?? 0));
                // On top of the products: the board's balance includes it.
                $table->setAttribute('service_fee', ServiceFee::forTable($table, (int) round($orderTotal * 100)) / 100);
            });

        return Inertia::render('restaurant/tables', [
            'tables' => $tables,
            'assignment' => TableAssignment::context($request->user()),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('restaurant/tables/create');
    }

    public function store(TableRequest $request): RedirectResponse
    {
        $restaurantId = $request->user()->effectiveRestaurantId();
        $customerToken = $request->validated('customer_token');

        // With a customer's QR the table is born linked to them; a bad or expired
        // code rolls the table back so staff can scan again.
        DB::transaction(function () use ($request, $restaurantId, $customerToken) {
            $table = RestaurantTable::create([
                'user_id' => $restaurantId,
                'title' => $request->validated('title'),
                'access_code' => RestaurantTable::generateUniqueCode(),
            ]);
            // Whoever on the team opens the table is in charge of it.
            $table->forceFill(['waiter_id' => TableAssignment::forNewTable($request->user())])->save();

            if (is_string($customerToken) && $customerToken !== '') {
                CustomerLink::consume($customerToken, $table);
            }
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $customerToken ? 'tables.msg_created_linked' : 'tables.msg_created',
        ]);

        return to_route('restaurant.tables.index');
    }

    public function show(Request $request, RestaurantTable $table): Response|SymfonyResponse
    {
        abort_unless($table->user_id === $request->user()->effectiveRestaurantId(), 403);

        if ($table->closed_at !== null) {
            return Inertia::location(route('restaurant.tables.index'));
        }

        $table->load([
            'payments' => fn ($q) => $q->with('registeredBy:id,name'),
        ]);

        $products = Product::where('user_id', $request->user()->effectiveRestaurantId())
            ->with('queue:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'picture', 'price', 'price_type', 'queue_id', 'sold_out_at']);

        // One line per (product, price): the same product can appear at different
        // prices (promotion), and several customers' rows at the same price are
        // summed. The waiter edits these totals; syncOrder maps them back to rows.
        $orderLines = DB::table('restaurant_table_product')
            ->where('restaurant_table_id', $table->id)
            ->join('products', 'products.id', '=', 'restaurant_table_product.product_id')
            ->groupBy(
                'products.id',
                'products.name',
                'products.picture',
                'products.price_type',
                'restaurant_table_product.price',
            )
            ->orderBy('products.name')
            ->select(
                'products.id',
                'products.name',
                'products.picture',
                'products.price_type',
                'restaurant_table_product.price',
            )
            ->selectRaw('SUM(restaurant_table_product.quantity) as quantity')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'picture' => $row->picture,
                'picture_url' => $row->picture ? Storage::disk('public')->url($row->picture) : null,
                'price_type' => $row->price_type,
                'price' => (float) $row->price,
                'quantity' => (int) $row->quantity,
            ]);

        $activeSales = Sale::query()
            ->activeFor($request->user()->effectiveRestaurantId())
            ->get(['product_id', 'sale_price']);

        $queueItems = QueueItem::where('restaurant_table_id', $table->id)
            ->whereIn('status', QueueItemStatus::open())
            ->with([
                'product:id,name,picture,price,price_type',
                'queue:id,name',
            ])
            ->orderBy('created_at')
            ->get();

        return Inertia::render('restaurant/tables/show', [
            'table' => $table,
            'products' => $products,
            'orderLines' => $orderLines,
            'activeSales' => $activeSales,
            'queueItems' => $queueItems,
            // Lets the client prove which version of the order it edited (see syncOrder).
            'orderVersion' => $this->orderVersion($table->id),
            // The fee is worked out in the browser too, on the order being edited.
            'serviceFee' => fn () => ServiceFee::terms($table),
            // Only a table nothing was ordered or paid at can be deleted (destroy()).
            'deletable' => fn () => ! self::hasConsumption($table->id),
            'assignment' => fn () => $this->assignmentFor($request->user(), $table),
            // Where items can go, or the table can join (the other open tables).
            'transferTargets' => fn () => RestaurantTable::where('user_id', $table->user_id)
                ->whereNull('closed_at')
                ->whereKeyNot($table->id)
                ->orderBy('title')
                ->get(['id', 'title']),
        ]);
    }

    /**
     * Moves some items to another open table (a customer changed tables): units of the
     * delivered lines and whole items still being prepared or waiting for delivery.
     */
    public function transferItems(Request $request, RestaurantTable $table): RedirectResponse
    {
        abort_unless($table->user_id === $request->user()->effectiveRestaurantId(), 403);

        /** @var array{to_table_id: int, lines?: list<array{product_id: int|string, price: float|string, quantity: int|string}>, queue_item_ids?: list<int|string>} $data */
        $data = $request->validate([
            'to_table_id' => ['required', 'integer'],
            'lines' => ['nullable', 'array', 'max:200', 'required_without:queue_item_ids'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.price' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'queue_item_ids' => ['nullable', 'array', 'max:200', 'required_without:lines'],
            'queue_item_ids.*' => ['integer'],
            'request_key' => ['nullable', 'string', 'max:64'],
        ]);

        $target = $this->transferTarget($table, (int) $data['to_table_id']);
        $lines = array_map(fn (array $l): array => [
            'product_id' => (int) $l['product_id'],
            'price' => (float) $l['price'],
            'quantity' => (int) $l['quantity'],
        ], $data['lines'] ?? []);
        $queueItemIds = array_map('intval', $data['queue_item_ids'] ?? []);

        $result = TableTransfer::MOVED;
        // A resent request (slow network, double tap) must not move the items twice.
        Idempotency::once($request, 'table-transfer:'.$table->id, function () use ($table, $target, $lines, $queueItemIds, &$result): void {
            $result = TableTransfer::moveItems($table, $target, $lines, $queueItemIds);
        });

        Inertia::flash('toast', match ($result) {
            TableTransfer::MOVED => ['type' => 'success', 'message' => 'tables.msg_items_transferred'],
            TableTransfer::CLOSED => ['type' => 'error', 'message' => 'tables.error_transfer_closed'],
            default => ['type' => 'error', 'message' => 'tables.error_transfer_stale'],
        });

        return to_route('restaurant.tables.show', $table);
    }

    /**
     * Joins this table into another (two groups sat together): everything moves there and
     * this table is removed. A resent request finds it already merged and just follows.
     */
    public function merge(Request $request, RestaurantTable $table): RedirectResponse
    {
        abort_unless($table->user_id === $request->user()->effectiveRestaurantId(), 403);

        /** @var array{into_table_id: int} $data */
        $data = $request->validate([
            'into_table_id' => ['required', 'integer'],
            'request_key' => ['nullable', 'string', 'max:64'],
        ]);

        $intoId = (int) $data['into_table_id'];

        if ($table->trashed()) {
            return to_route('restaurant.tables.show', $intoId);
        }

        $target = $this->transferTarget($table, $intoId);

        $result = TableTransfer::MOVED;
        Idempotency::once($request, 'table-merge:'.$table->id, function () use ($table, $target, &$result): void {
            $result = TableTransfer::merge($table, $target);
        });

        if ($result !== TableTransfer::MOVED) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $result === TableTransfer::PAYMENT_IN_FLIGHT ? 'tables.error_merge_payment' : 'tables.error_transfer_closed',
            ]);

            return to_route('restaurant.tables.show', $table);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'tables.msg_tables_merged']);

        return to_route('restaurant.tables.show', $target);
    }

    /** Another open table of the same restaurant, or a validation error. */
    private function transferTarget(RestaurantTable $table, int $targetId): RestaurantTable
    {
        abort_if($table->closed_at !== null, 422, __('tables.error_already_closed'));

        $target = RestaurantTable::whereKey($targetId)
            ->where('user_id', $table->user_id)
            ->whereNull('closed_at')
            ->whereKeyNot($table->id)
            ->first(['id', 'user_id', 'title', 'closed_at']);

        if ($target === null) {
            throw ValidationException::withMessages(['to_table_id' => __('tables.error_transfer_target')]);
        }

        return $target;
    }

    /** A team member takes a free table. */
    public function claim(Request $request, RestaurantTable $table): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignable($actor, $table);
        abort_unless(TableAssignment::canServe($actor), 403);

        Inertia::flash('toast', TableAssignment::claim($table, $actor)
            ? ['type' => 'success', 'message' => 'tables.msg_claimed']
            : ['type' => 'error', 'message' => 'tables.error_already_taken']);

        return back();
    }

    /** The waiter in charge lets go of the table, leaving it free. */
    public function release(Request $request, RestaurantTable $table): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignable($actor, $table);

        if (TableAssignment::release($table, $actor)) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'tables.msg_released']);
        }

        return back();
    }

    /** The owner or a manager gives the table to a team member, or frees it (waiter_id null). */
    public function assignWaiter(Request $request, RestaurantTable $table): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignable($actor, $table);
        abort_unless(TableAssignment::canAssign($actor), 403);

        $restaurantId = $actor->effectiveRestaurantId();
        /** @var array{waiter_id: int|null} $data */
        $data = $request->validate([
            'waiter_id' => [
                'present',
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($restaurantId): void {
                    if ($value !== null && ! TableAssignment::isCandidate($restaurantId, (int) $value)) {
                        $fail(__('tables.error_not_a_waiter'));
                    }
                },
            ],
        ]);

        TableAssignment::assign($table, $data['waiter_id'] === null ? null : (int) $data['waiter_id']);

        Inertia::flash('toast', ['type' => 'success', 'message' => $data['waiter_id'] === null ? 'tables.msg_unassigned' : 'tables.msg_assigned']);

        return back();
    }

    /** The restaurant's open table, with the feature on. */
    private function ensureAssignable(User $actor, RestaurantTable $table): void
    {
        abort_unless($table->user_id === $actor->effectiveRestaurantId(), 403);
        abort_if($table->closed_at !== null, 422, __('tables.error_already_closed'));
        abort_unless(TableAssignment::enabled($table->user_id), 403);
    }

    /**
     * The table's waiter and what the viewer may do about it.
     *
     * @return array<string, mixed>
     */
    private function assignmentFor(User $actor, RestaurantTable $table): array
    {
        $context = TableAssignment::context($actor);
        $waiter = $table->waiter_id === null ? null : User::find($table->waiter_id, ['id', 'name']);

        return [
            ...$context,
            'waiter' => $waiter === null ? null : ['id' => $waiter->id, 'name' => $waiter->name],
            // Whom the owner or a manager can give the table to.
            'candidates' => $context['canAssign'] ? TableAssignment::candidates($table->user_id) : [],
        ];
    }

    /**
     * Whether anything was ordered or paid at the table: such a table is part of the
     * restaurant's records and is never deleted, by anyone. Staff-entered lines,
     * customers' orders (queued or not), payments and online checkouts all count.
     */
    private static function hasConsumption(int $tableId): bool
    {
        return DB::table('restaurant_table_product')->where('restaurant_table_id', $tableId)->exists()
            || DB::table('queue_items')->where('restaurant_table_id', $tableId)->exists()
            || DB::table('table_payments')->where('restaurant_table_id', $tableId)->exists()
            || DB::table('online_payments')->where('restaurant_table_id', $tableId)->exists();
    }

    public function edit(Request $request, RestaurantTable $table): Response
    {
        abort_unless($table->user_id === $request->user()->effectiveRestaurantId(), 403);

        return Inertia::render('restaurant/tables/edit', [
            'table' => $table,
        ]);
    }

    public function update(TableRequest $request, RestaurantTable $table): RedirectResponse
    {
        abort_unless($table->user_id === $request->user()->effectiveRestaurantId(), 403);

        if ($request->has('title')) {
            $table->update(['title' => $request->validated('title')]);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'tables.msg_updated']);

            return to_route('restaurant.tables.index');
        }

        $result = $this->syncOrder($request, $table);

        if ($result !== self::SYNC_SAVED) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $result === self::SYNC_SOLD_OUT ? 'tables.error_sold_out' : 'tables.msg_order_conflict',
            ]);

            return to_route('restaurant.tables.show', $table);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'tables.msg_products_updated']);

        return to_route('restaurant.tables.show', $table);
    }

    public function addPayment(Request $request, RestaurantTable $table): RedirectResponse
    {
        abort_unless($table->user_id === $request->user()->effectiveRestaurantId(), 403);
        abort_if($table->closed_at !== null, 422, __('tables.error_already_closed'));

        $validated = $request->validate([
            // "online" is recorded only by the app, when Stripe confirms a customer's payment.
            'method' => ['required', Rule::enum(PaymentMethod::class)->only(PaymentMethod::manual())],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            'request_key' => ['nullable', 'string', 'max:64'],
        ]);

        // A retried submit (slow network, double tap) must not record the payment twice.
        Idempotency::once($request, 'table-payment:'.$table->id, function () use ($request, $table, $validated): void {
            $table->payments()->create([
                'method' => $validated['method'],
                'amount' => $validated['amount'],
                'registered_by_id' => $request->user()->id,
                'registered_by_type' => $request->user()->account_type->value,
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'tables.msg_payment_added']);

        return to_route('restaurant.tables.show', $table);
    }

    public function close(Request $request, RestaurantTable $table): RedirectResponse
    {
        abort_unless($table->user_id === $request->user()->effectiveRestaurantId(), 403);
        abort_if($table->closed_at !== null, 422, __('tables.error_already_closed'));

        // Locked so a delivery or payment landing at the same moment is counted
        // before the totals are compared.
        $closed = DB::transaction(function () use ($table): bool {
            $locked = RestaurantTable::whereKey($table->id)->lockForUpdate()->firstOrFail();

            if ($locked->closed_at !== null) {
                return true;
            }

            $paymentTotal = (float) $locked->payments()->sum('amount');

            // In cents: floating-point sums (e.g. 3 x 0.10) must not block a paid table.
            // The bill is the products plus the service fee.
            if ((int) round($paymentTotal * 100) < ServiceFee::bill($locked->id)['total']) {
                return false;
            }

            $locked->closed_at = now();
            $locked->access_code = null;
            $locked->save();

            // Whatever was still being prepared or waiting for a waiter never reached
            // the customer. It isn't billed, but the restaurant must see it (dashboard).
            QueueItem::where('restaurant_table_id', $locked->id)
                ->whereIn('status', QueueItemStatus::open())
                ->update(['status' => QueueItemStatus::ABANDONED->value, 'updated_at' => now()]);

            // Nobody is left to answer: the calls board drops the table's calls.
            TableCall::resolve($locked->id, TableCall::CLOSED);

            return true;
        });

        if (! $closed) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'tables.msg_insufficient_payment']);

            return to_route('restaurant.tables.show', $table);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'tables.msg_closed']);

        return to_route('restaurant.tables.index');
    }

    /**
     * Takes the service fee off the table's bill, or puts it back: in Brazil the customer
     * may decline it. An online payment held meanwhile is refunded what it overpays
     * (TableCheckout::markPaid credits only what the bill still owes).
     */
    public function waiveServiceFee(Request $request, RestaurantTable $table): RedirectResponse
    {
        abort_unless($table->user_id === $request->user()->effectiveRestaurantId(), 403);
        abort_if($table->closed_at !== null, 422, __('tables.error_already_closed'));
        abort_if(ServiceFee::terms($table) === null, 404);

        /** @var array{waived: bool} $data */
        $data = $request->validate([
            'waived' => ['required', 'boolean'],
        ]);

        // Locked like every change to the bill, so closing or a payment sees one fee or the other.
        DB::transaction(function () use ($table, $data): void {
            RestaurantTable::whereKey($table->id)->lockForUpdate()->first(['id']);

            RestaurantTable::whereKey($table->id)
                ->whereNull('closed_at')
                ->update(['service_fee_waived' => $data['waived']]);
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $data['waived'] ? 'tables.msg_service_fee_waived' : 'tables.msg_service_fee_restored',
        ]);

        return to_route('restaurant.tables.show', $table);
    }

    public function destroy(Request $request, RestaurantTable $table): RedirectResponse
    {
        abort_unless($table->user_id === $request->user()->effectiveRestaurantId(), 403);

        // Locked like every change to the order, so nothing is ordered or paid while deleting.
        $deleted = DB::transaction(function () use ($table): bool {
            RestaurantTable::whereKey($table->id)->lockForUpdate()->first();

            if (self::hasConsumption($table->id)) {
                return false;
            }

            $table->delete();
            TableCall::resolve($table->id, TableCall::CLOSED);

            return true;
        });

        if (! $deleted) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'tables.error_delete_with_consumption']);

            return back(fallback: route('restaurant.tables.show', $table));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'tables.msg_deleted']);

        return to_route('restaurant.tables.index');
    }

    /**
     * Brings the table's delivered lines to the quantities the waiter submitted
     * (one total per product and price) and queues new kitchen items.
     *
     * Only the difference is applied: unchanged lines are left alone, so they
     * keep the customer who ordered them. Added units go on an unattributed
     * line; removed units come off the unattributed line first, then off the
     * customers' lines.
     *
     * Changes nothing and says why when the order changed since the client
     * loaded it (a delivery was marked or another waiter saved), so a stale
     * screen can't overwrite lines it never saw; or when it would add units of a
     * sold-out product (lowering or keeping what was already served is fine).
     *
     * @return self::SYNC_*
     */
    private function syncOrder(TableRequest $request, RestaurantTable $table): string
    {
        $ownerId = $request->user()->effectiveRestaurantId();
        /** @var array<int, array<string, mixed>> $submitted */
        $submitted = $request->input('products', []);
        /** @var array<int, array<string, mixed>> $queueAdditions */
        $queueAdditions = $request->input('queue_additions', []);

        $referencedIds = array_map('intval', array_merge(
            array_column($submitted, 'product_id'),
            array_column($queueAdditions, 'product_id'),
        ));
        $ownedProducts = Product::where('user_id', $ownerId)
            ->whereIn('id', $referencedIds)
            ->get(['id', 'queue_id', 'sold_out_at'])
            ->keyBy('id');

        /** @var array<string, array{product_id: int, price: float, quantity: int}> $desired */
        $desired = [];
        foreach ($submitted as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 0);
            $price = (float) ($item['price'] ?? 0);

            if (! $ownedProducts->has($productId) || $qty <= 0 || $price < 0) {
                continue;
            }

            $key = self::lineKey($productId, $price);
            $desired[$key] ??= ['product_id' => $productId, 'price' => $price, 'quantity' => 0];
            $desired[$key]['quantity'] += $qty;
        }

        $now = now();
        $queueRows = [];
        foreach ($queueAdditions as $item) {
            $product = $ownedProducts->get((int) ($item['product_id'] ?? 0));
            $qty = (int) ($item['quantity'] ?? 0);

            if ($product === null || $product->queue_id === null || $qty <= 0) {
                continue;
            }

            $queueRows[] = [
                'restaurant_table_id' => $table->id,
                'product_id' => $product->id,
                'queue_id' => $product->queue_id,
                'quantity' => $qty,
                'notes' => QueueItem::cleanNotes($item['notes'] ?? null),
                'price' => (float) ($item['price'] ?? 0),
                'status' => QueueItemStatus::PENDING->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $clientVersion = $request->validated('order_version');
        $soldOut = $ownedProducts->filter(fn (Product $p): bool => $p->isSoldOut())->keys()->all();

        // Nothing sold out goes to the kitchen.
        foreach ($queueRows as $row) {
            if (in_array($row['product_id'], $soldOut, true)) {
                return self::SYNC_SOLD_OUT;
            }
        }

        return DB::transaction(function () use ($table, $desired, $queueRows, $clientVersion, $soldOut): string {
            // Serializes with QueueItemController::markDelivered and other saves on this table.
            RestaurantTable::whereKey($table->id)->lockForUpdate()->first(['id']);

            if (is_string($clientVersion) && $clientVersion !== $this->orderVersion($table->id)) {
                return self::SYNC_CONFLICT;
            }

            // Nor are more units of it put on the bill than were already served.
            if ($soldOut !== []) {
                $served = DB::table('restaurant_table_product')
                    ->where('restaurant_table_id', $table->id)
                    ->whereIn('product_id', $soldOut)
                    ->groupBy('product_id')
                    ->selectRaw('product_id, SUM(quantity) as quantity')
                    ->pluck('quantity', 'product_id');

                foreach ($soldOut as $productId) {
                    $wanted = array_sum(array_map(
                        fn (array $line): int => $line['product_id'] === $productId ? $line['quantity'] : 0,
                        $desired,
                    ));

                    if ($wanted > (int) ($served[$productId] ?? 0)) {
                        return self::SYNC_SOLD_OUT;
                    }
                }
            }

            $current = DB::table('restaurant_table_product')
                ->where('restaurant_table_id', $table->id)
                ->orderByDesc('ordered_by_user_id')
                ->get(['product_id', 'ordered_by_user_id', 'price', 'quantity'])
                ->groupBy(fn ($row) => self::lineKey((int) $row->product_id, (float) $row->price));

            foreach ($current as $key => $rows) {
                $this->applyLineQuantity($table->id, $rows, $desired[$key]['quantity'] ?? 0);
                unset($desired[$key]);
            }

            // Product/price pairs the table didn't have yet.
            foreach ($desired as $line) {
                DB::table('restaurant_table_product')->insert([
                    'restaurant_table_id' => $table->id,
                    'product_id' => $line['product_id'],
                    'ordered_by_user_id' => null,
                    'price' => $line['price'],
                    'quantity' => $line['quantity'],
                ]);
            }

            if ($queueRows !== []) {
                QueueItem::insert($queueRows);
            }

            return self::SYNC_SAVED;
        });
    }

    /**
     * Sets the total quantity of one (product, price) line, spread over its rows.
     * Unattributed units are the first to grow or shrink; customers' rows only
     * shrink once the unattributed ones are gone.
     *
     * @param  Collection<int, \stdClass>  $rows  all rows of the line
     */
    private function applyLineQuantity(int $tableId, Collection $rows, int $target): void
    {
        $productId = $rows->first()->product_id;
        $price = $rows->first()->price;
        $line = fn () => DB::table('restaurant_table_product')
            ->where('restaurant_table_id', $tableId)
            ->where('product_id', $productId)
            ->where('price', $price);

        $total = (int) $rows->sum('quantity');

        if ($target === $total) {
            return;
        }

        if ($target === 0) {
            $line()->delete();

            return;
        }

        $unattributed = (int) $rows->whereNull('ordered_by_user_id')->sum('quantity');

        if ($target > $total) {
            $newUnattributed = $unattributed + ($target - $total);
            $toRemove = 0;
        } else {
            $newUnattributed = max(0, $unattributed - ($total - $target));
            $toRemove = ($total - $target) - ($unattributed - $newUnattributed);
        }

        // Collapse the unattributed units into a single row.
        $line()->whereNull('ordered_by_user_id')->delete();

        if ($newUnattributed > 0) {
            DB::table('restaurant_table_product')->insert([
                'restaurant_table_id' => $tableId,
                'product_id' => $productId,
                'ordered_by_user_id' => null,
                'price' => $price,
                'quantity' => $newUnattributed,
            ]);
        }

        foreach ($rows->whereNotNull('ordered_by_user_id') as $row) {
            if ($toRemove <= 0) {
                break;
            }

            $quantity = (int) $row->quantity;
            $take = min($quantity, $toRemove);
            $toRemove -= $take;
            $query = $line()->where('ordered_by_user_id', $row->ordered_by_user_id);

            if ($take === $quantity) {
                $query->delete();
            } else {
                $query->update(['quantity' => $quantity - $take]);
            }
        }
    }

    private static function lineKey(int $productId, float $price): string
    {
        return $productId.'|'.number_format($price, 2, '.', '');
    }

    /**
     * Fingerprint of the table's delivered lines. Any delivery, edit or removal changes it.
     */
    private function orderVersion(int $tableId): string
    {
        $fingerprint = DB::table('restaurant_table_product')
            ->where('restaurant_table_id', $tableId)
            ->orderBy('product_id')
            ->orderBy('price')
            ->orderBy('ordered_by_user_id')
            ->orderBy('quantity')
            ->get(['product_id', 'ordered_by_user_id', 'price', 'quantity'])
            ->map(fn ($row) => implode(':', [
                $row->product_id,
                $row->ordered_by_user_id ?? '-',
                number_format((float) $row->price, 2, '.', ''),
                $row->quantity,
            ]))
            ->implode('|');

        return md5($fingerprint);
    }
}
