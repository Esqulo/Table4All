<?php

namespace App\Support;

use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The service fee ("taxa de serviço") on a table's bill.
 *
 * The restaurant chooses a fixed amount per table or a percentage of the products, and for
 * a percentage an optional cap. The terms are copied onto each table when it opens, so a
 * bill never changes because the setting did; staff can waive the fee on a table (in Brazil
 * it is optional for the customer). A table with nothing delivered owes no fee.
 *
 * Money is in integer cents, with the percentage as basis points, so the server and the
 * browser (resources/js/lib/service-fee.ts) round the same way.
 */
final class ServiceFee
{
    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    public const TYPES = [self::PERCENT, self::FIXED];

    /** Highest percentage a restaurant can charge. */
    public const MAX_PERCENT = 30;

    /** Highest fixed amount or cap, in reais. */
    public const MAX_AMOUNT = 9999.99;

    /** The columns a new table copies from its restaurant (restaurant_tables and users share them). */
    public const COLUMNS = ['service_fee_type', 'service_fee_value', 'service_fee_cap'];

    /**
     * The fee in cents on $productCents of products.
     */
    public static function cents(?string $type, mixed $value, mixed $cap, int $productCents): int
    {
        if ($productCents <= 0 || $value === null) {
            return 0;
        }

        // Basis points for a percentage, cents for a fixed amount.
        $units = (int) round(100 * (float) $value);

        if ($units <= 0) {
            return 0;
        }

        if ($type === self::FIXED) {
            return $units;
        }

        if ($type !== self::PERCENT) {
            return 0;
        }

        // Rounded half up, in integers.
        $fee = intdiv($productCents * $units + 5000, 10000);

        return $cap === null ? $fee : min($fee, (int) round(100 * (float) $cap));
    }

    /**
     * The table's fee in cents on $productCents of products (0 when waived or none).
     *
     * @param  RestaurantTable|\stdClass  $table  a row with the service_fee_* columns
     */
    public static function forTable(RestaurantTable|\stdClass $table, int $productCents): int
    {
        if ((bool) ($table->service_fee_waived ?? false)) {
            return 0;
        }

        return self::cents($table->service_fee_type ?? null, $table->service_fee_value ?? null, $table->service_fee_cap ?? null, $productCents);
    }

    /**
     * The products delivered to the table, in cents (snapshotted prices).
     */
    public static function productCents(int $tableId): int
    {
        return (int) round(100 * (float) DB::table('restaurant_table_product')
            ->where('restaurant_table_id', $tableId)
            ->sum(DB::raw('price * quantity')));
    }

    /**
     * What the table owes, in cents: products, fee and both together.
     *
     * @return array{products: int, fee: int, total: int}
     */
    public static function bill(int $tableId): array
    {
        $products = self::productCents($tableId);
        $table = DB::table('restaurant_tables')
            ->where('id', $tableId)
            ->first([...self::COLUMNS, 'service_fee_waived']);
        $fee = $table === null ? 0 : self::forTable($table, $products);

        return ['products' => $products, 'fee' => $fee, 'total' => $products + $fee];
    }

    /**
     * The table's fee terms for the screens, or null when it has none.
     *
     * @return array{type: string, value: float, cap: float|null, waived: bool}|null
     */
    public static function terms(RestaurantTable $table): ?array
    {
        if (! in_array($table->service_fee_type, self::TYPES, true) || $table->service_fee_value === null) {
            return null;
        }

        return [
            'type' => (string) $table->service_fee_type,
            'value' => (float) $table->service_fee_value,
            'cap' => $table->service_fee_cap === null ? null : (float) $table->service_fee_cap,
            'waived' => (bool) $table->service_fee_waived,
        ];
    }

    /**
     * The restaurant's current terms, copied onto a table it opens.
     *
     * @return array{service_fee_type: string|null, service_fee_value: string|null, service_fee_cap: string|null}
     */
    public static function snapshot(int $restaurantId): array
    {
        $row = DB::table('users')->where('id', $restaurantId)->first(self::COLUMNS);

        return [
            'service_fee_type' => $row->service_fee_type ?? null,
            'service_fee_value' => $row->service_fee_value ?? null,
            'service_fee_cap' => $row->service_fee_cap ?? null,
        ];
    }

    /**
     * The restaurant's settings for the venue page.
     *
     * @return array{type: string|null, value: float|null, cap: float|null}
     */
    public static function settings(User $restaurant): array
    {
        return [
            'type' => $restaurant->service_fee_type,
            'value' => $restaurant->service_fee_value === null ? null : (float) $restaurant->service_fee_value,
            'cap' => $restaurant->service_fee_cap === null ? null : (float) $restaurant->service_fee_cap,
        ];
    }
}
