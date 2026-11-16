<?php

namespace Tests\Support;

use App\Enums\QueueItemStatus;
use App\Models\Product;
use App\Models\QueueItem;
use App\Models\RestaurantQueue;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Builders for the restaurant domain. There are no factories for these
 * models, and the tests read better with the intent in the method name.
 */
trait RestaurantFixtures
{
    protected function restaurant(array $attributes = []): User
    {
        return User::factory()->create(['account_type' => 'restaurant', ...$attributes]);
    }

    protected function waiterOf(User $restaurant): User
    {
        return User::factory()->create(['account_type' => 'waiter', 'restaurant_id' => $restaurant->id]);
    }

    protected function customer(): User
    {
        return User::factory()->create();
    }

    protected function admin(): User
    {
        return User::factory()->create(['account_type' => 'admin']);
    }

    protected function product(User $restaurant, array $attributes = []): Product
    {
        return Product::create([
            'user_id' => $restaurant->id,
            'name' => 'Chopp',
            'price' => 10,
            'price_type' => 'unit',
            ...$attributes,
        ]);
    }

    protected function kitchenQueue(User $restaurant, string $name = 'Cozinha'): RestaurantQueue
    {
        return RestaurantQueue::create(['user_id' => $restaurant->id, 'name' => $name]);
    }

    protected function openTable(User $restaurant, array $attributes = []): RestaurantTable
    {
        return RestaurantTable::create([
            'user_id' => $restaurant->id,
            'title' => 'Mesa 1',
            'access_code' => RestaurantTable::generateUniqueCode(),
            ...$attributes,
        ]);
    }

    protected function closedTable(User $restaurant, array $attributes = []): RestaurantTable
    {
        $table = $this->openTable($restaurant, $attributes);
        $table->forceFill(['closed_at' => now(), 'access_code' => null])->save();

        return $table;
    }

    protected function queueItem(RestaurantTable $table, Product $product, array $attributes = []): QueueItem
    {
        return QueueItem::create([
            'restaurant_table_id' => $table->id,
            'product_id' => $product->id,
            'queue_id' => $product->queue_id,
            'quantity' => 1,
            'price' => $product->price,
            'status' => QueueItemStatus::PENDING->value,
            ...$attributes,
        ]);
    }

    /** A delivered line on the table's bill. */
    protected function billLine(RestaurantTable $table, Product $product, int $quantity, float $price, ?int $orderedBy = null): void
    {
        DB::table('restaurant_table_product')->insert([
            'restaurant_table_id' => $table->id,
            'product_id' => $product->id,
            'ordered_by_user_id' => $orderedBy,
            'quantity' => $quantity,
            'price' => $price,
        ]);
    }
}
