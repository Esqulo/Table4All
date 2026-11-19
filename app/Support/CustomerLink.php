<?php

namespace App\Support;

use App\Enums\TableCustomerStatus;
use App\Models\RestaurantTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One-time QR codes a customer shows to staff, so the table staff open is
 * linked to them: the customer becomes the table's responsible.
 */
class CustomerLink
{
    public const TTL_SECONDS = 60;

    /** What the QR encodes; the staff scanner only accepts this shape. */
    public const PAYLOAD_PREFIX = 't4a-customer:';

    /** A fresh code for the customer. Older unused ones stop working. */
    public static function issue(int $userId): string
    {
        $token = Str::random(40);
        $now = now();

        DB::transaction(function () use ($userId, $token, $now) {
            DB::table('customer_link_tokens')
                ->where('user_id', $userId)
                ->whereNull('used_at')
                ->delete();

            DB::table('customer_link_tokens')->insert([
                'user_id' => $userId,
                'token' => $token,
                'expires_at' => $now->copy()->addSeconds(self::TTL_SECONDS),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return $token;
    }

    /** Invalidates the customer's unused code. */
    public static function cancel(int $userId): void
    {
        DB::table('customer_link_tokens')
            ->where('user_id', $userId)
            ->whereNull('used_at')
            ->delete();
    }

    /**
     * Uses the code up on $table, making its customer the responsible. Call inside
     * the transaction that creates the table: an invalid, expired or already used
     * code throws a validation error and nothing is kept.
     */
    public static function consume(string $token, RestaurantTable $table): int
    {
        $row = DB::table('customer_link_tokens')
            ->where('token', $token)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->first(['id', 'user_id']);

        if (! $row) {
            throw ValidationException::withMessages([
                'customer_token' => __('tables.customer_qr_invalid'),
            ]);
        }

        $now = now();
        $customerId = (int) $row->user_id;

        DB::table('customer_link_tokens')
            ->where('id', $row->id)
            ->update(['used_at' => $now, 'restaurant_table_id' => $table->id, 'updated_at' => $now]);

        $table->forceFill(['responsible_user_id' => $customerId])->save();

        DB::table('restaurant_table_customers')->insert([
            'restaurant_table_id' => $table->id,
            'user_id' => $customerId,
            'status' => TableCustomerStatus::APPROVED->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $customerId;
    }

    /**
     * The customer's latest code, for their screen: still showing it, or the
     * table it opened. Null once it's long gone.
     *
     * @return array{payload: string, expires_in: int, table_code: string|null}|null
     */
    public static function latestFor(int $userId): ?array
    {
        $row = DB::table('customer_link_tokens')
            ->where('customer_link_tokens.user_id', $userId)
            ->where('customer_link_tokens.created_at', '>', now()->subSeconds(self::TTL_SECONDS * 2))
            ->leftJoin('restaurant_tables', function ($join) {
                $join->on('restaurant_tables.id', '=', 'customer_link_tokens.restaurant_table_id')
                    ->whereNull('restaurant_tables.closed_at')
                    ->whereNull('restaurant_tables.deleted_at');
            })
            ->orderByDesc('customer_link_tokens.id')
            ->first([
                'customer_link_tokens.token',
                'customer_link_tokens.expires_at',
                'customer_link_tokens.used_at',
                'restaurant_tables.access_code',
            ]);

        if (! $row) {
            return null;
        }

        $expiresIn = $row->used_at !== null
            ? 0
            : max(0, (int) now()->diffInSeconds($row->expires_at, false));

        return [
            'payload' => self::PAYLOAD_PREFIX.$row->token,
            'expires_in' => $expiresIn,
            'table_code' => $row->access_code,
        ];
    }
}
