<?php

namespace App\Http\Controllers\Customer;

use App\Enums\QueueItemStatus;
use App\Enums\TableCustomerStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\QueueItem;
use App\Models\RestaurantTable;
use App\Models\Sale;
use App\Models\User;
use App\Support\Idempotency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        ]);
    }

    public function show(Request $request, string $code): Response|RedirectResponse
    {
        $table = RestaurantTable::where('access_code', $code)
            ->whereNull('closed_at')
            ->whereNull('deleted_at')
            ->first(['id', 'user_id', 'title', 'responsible_user_id']);

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

        $activeSales = Sale::activePriceMap($table->user_id);

        $menu = Product::where('user_id', $table->user_id)
            ->with('category:id,name')
            ->orderBy('name')
            ->get(['id', 'category_id', 'name', 'description', 'picture', 'price', 'price_type', 'queue_id'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'category_id' => $p->category_id,
                'category_name' => $p->category?->name,
                'name' => $p->name,
                'description' => $p->description,
                'price' => (float) ($activeSales[$p->id] ?? $p->price),
                'price_type' => $p->price_type,
                'has_queue' => $p->queue_id !== null,
                'picture_url' => $p->picture ? Storage::disk('public')->url($p->picture) : null,
            ]);

        $products = DB::table('restaurant_table_product')
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
            ]);

        $preparing = QueueItem::where('restaurant_table_id', $table->id)
            ->whereIn('status', QueueItemStatus::open())
            ->with(['product:id,name', 'orderedBy:id,name,email'])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($item) => [
                'name' => $item->product->name,
                'price' => (float) $item->price,
                'quantity' => (int) $item->quantity,
                'has_queue' => $item->queue_id !== null,
                'ordered_by' => $item->orderedBy !== null ? ($item->orderedBy->name ?? $item->orderedBy->email) : null,
            ]);

        $total = (float) DB::table('restaurant_table_product')
            ->where('restaurant_table_id', $table->id)
            ->sum(DB::raw('price * quantity'));

        $paid = (float) $table->payments()->sum('amount');

        return Inertia::render('customer/show', [
            'code' => $code,
            'menu' => $menu,
            'products' => $products,
            'preparing' => $preparing,
            'total' => $total,
            'paid' => $paid,
            'remaining' => max(0.0, $total - $paid),
            'isResponsible' => $isResponsible,
            'members' => $this->members($table->id, $table->responsible_user_id, $user->id),
            'joinRequests' => $isResponsible ? $this->pendingRequests($table->id) : [],
        ]);
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
            'request_key' => ['nullable', 'string', 'max:64'],
        ]);

        $productIds = array_column($validated['items'], 'product_id');
        $products = Product::where('user_id', $table->user_id)
            ->whereIn('id', $productIds)
            ->get(['id', 'queue_id', 'price'])
            ->keyBy('id');
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
