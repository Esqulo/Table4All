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
use App\Support\CustomerLink;
use App\Support\Idempotency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class TableController extends Controller
{
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
                // Thumbnails only: the card shows at most five.
                'products' => fn ($q) => $q
                    ->select('products.id', 'products.name', 'products.picture')
                    ->limit(5),
            ])
            ->orderBy('title')
            ->get()
            ->each(function (RestaurantTable $table): void {
                $table->setAttribute('order_total', (float) $table->getAttribute('order_total'));
                $table->setAttribute('paid_total', (float) ($table->getAttribute('paid_total') ?? 0));
            });

        return Inertia::render('restaurant/tables', [
            'tables' => $tables,
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
            ->get(['id', 'name', 'picture', 'price', 'price_type', 'queue_id']);

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
        ]);
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

        if (! $this->syncOrder($request, $table)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'tables.msg_order_conflict']);

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

            $productTotal = (float) DB::table('restaurant_table_product')
                ->where('restaurant_table_id', $locked->id)
                ->sum(DB::raw('price * quantity'));

            $paymentTotal = (float) $locked->payments()->sum('amount');

            // In cents: floating-point sums (e.g. 3 x 0.10) must not block a paid table.
            if ((int) round($paymentTotal * 100) < (int) round($productTotal * 100)) {
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

            return true;
        });

        if (! $closed) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'tables.msg_insufficient_payment']);

            return to_route('restaurant.tables.show', $table);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'tables.msg_closed']);

        return to_route('restaurant.tables.index');
    }

    public function destroy(Request $request, RestaurantTable $table): RedirectResponse
    {
        abort_unless($table->user_id === $request->user()->effectiveRestaurantId(), 403);

        $table->delete();

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
     * Returns false (and changes nothing) when the order changed since the
     * client loaded it, e.g. a delivery was marked or another waiter saved, so
     * a stale screen can't overwrite lines it never saw.
     */
    private function syncOrder(TableRequest $request, RestaurantTable $table): bool
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
            ->get(['id', 'queue_id'])
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
                'price' => (float) ($item['price'] ?? 0),
                'status' => QueueItemStatus::PENDING->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $clientVersion = $request->validated('order_version');

        return DB::transaction(function () use ($table, $desired, $queueRows, $clientVersion): bool {
            // Serializes with QueueItemController::markDelivered and other saves on this table.
            RestaurantTable::whereKey($table->id)->lockForUpdate()->first(['id']);

            if (is_string($clientVersion) && $clientVersion !== $this->orderVersion($table->id)) {
                return false;
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

            return true;
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
