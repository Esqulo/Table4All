<?php

namespace App\Support\Privacy;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything Table4All keeps about one person, as plain data (LGPD Art. 18 II and V:
 * access and portability). Only what is tied to their own user id: other people at the
 * same table and the restaurants' internal data stay out.
 *
 * Every lookup filters on an indexed user column; this runs rarely and is throttled.
 */
final class PersonalDataExport
{
    /** @return array<string, mixed> */
    public static function for(User $user): array
    {
        $id = $user->id;

        return [
            'generated_at' => now()->toIso8601String(),
            'controller' => config('legal.company_name'),
            'account' => [
                'id' => $id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'account_type' => $user->account_type->value,
                'avatar' => $user->avatar_url,
                'created_at' => $user->created_at?->toIso8601String(),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'terms_accepted_at' => $user->terms_accepted_at?->toIso8601String(),
                'terms_version' => $user->terms_version,
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                // An admin switched the account off: when, and the reason they recorded.
                'deactivated_at' => $user->deactivated_at?->toIso8601String(),
                'deactivation_reason' => $user->deactivation_reason,
            ],
            'restaurant_profile' => $user->account_type === AccountType::RESTAURANT ? [
                'handle' => $user->handle,
                'address' => $user->address,
                'ifood_url' => $user->ifood_url,
                'profile_public' => $user->profile_public,
            ] : null,
            'passkeys' => self::rows(
                DB::table('passkeys')->where('user_id', $id)->orderBy('id'),
                ['name', 'last_used_at', 'created_at'],
            ),
            'sessions' => self::rows(
                DB::table('sessions')->where('user_id', $id)->orderByDesc('last_activity'),
                ['ip_address', 'user_agent', 'last_activity'],
            )->map(fn (array $row) => [...$row, 'last_activity' => date(DATE_ATOM, (int) $row['last_activity'])]),
            'tables_joined' => self::rows(
                DB::table('restaurant_table_customers as c')
                    ->join('restaurant_tables as t', 't.id', '=', 'c.restaurant_table_id')
                    ->join('users as r', 'r.id', '=', 't.user_id')
                    ->where('c.user_id', $id)
                    ->orderBy('c.id'),
                ['r.name as restaurant', 't.title as table', 'c.status', 'c.created_at as joined_at', 't.closed_at'],
            ),
            'items_ordered' => self::rows(
                DB::table('queue_items as q')
                    ->join('products as p', 'p.id', '=', 'q.product_id')
                    ->join('restaurant_tables as t', 't.id', '=', 'q.restaurant_table_id')
                    ->join('users as r', 'r.id', '=', 't.user_id')
                    ->where('q.ordered_by_user_id', $id)
                    ->orderBy('q.id'),
                ['r.name as restaurant', 'p.name as product', 'q.quantity', 'q.price', 'q.notes', 'q.status', 'q.created_at'],
            ),
            'bill_lines' => self::rows(
                DB::table('restaurant_table_product as b')
                    ->join('products as p', 'p.id', '=', 'b.product_id')
                    ->join('restaurant_tables as t', 't.id', '=', 'b.restaurant_table_id')
                    ->join('users as r', 'r.id', '=', 't.user_id')
                    ->where('b.ordered_by_user_id', $id)
                    ->orderBy('t.id'),
                ['r.name as restaurant', 't.title as table', 'p.name as product', 'b.quantity', 'b.price', 't.closed_at'],
            ),
            'online_payments' => self::rows(
                DB::table('online_payments as o')
                    ->join('users as r', 'r.id', '=', 'o.restaurant_id')
                    ->where('o.user_id', $id)
                    ->orderBy('o.id'),
                ['r.name as restaurant', 'o.status', 'o.amount_cents', 'o.discount_cents', 'o.charge_cents', 'o.refunded_cents', 'o.paid_at', 'o.refunded_at', 'o.created_at'],
            ),
            'payments_registered' => self::rows(
                DB::table('table_payments')->where('registered_by_id', $id)->orderBy('id'),
                ['restaurant_table_id', 'method', 'amount', 'created_at'],
            ),
            'table_calls' => self::rows(
                DB::table('table_calls')->where('requested_by_user_id', $id)->orderBy('id'),
                ['restaurant_table_id', 'type', 'resolution', 'created_at', 'resolved_at'],
            ),
            'table_messages' => self::rows(
                DB::table('table_messages')->where('user_id', $id)->orderBy('id'),
                ['restaurant_table_id', 'body', 'created_at'],
            ),
            'table_reactions' => self::rows(
                DB::table('table_reactions')->where('user_id', $id)->orderBy('id'),
                ['restaurant_table_id', 'emoji', 'created_at'],
            ),
            'post_comments' => self::rows(
                DB::table('post_comments')->where('user_id', $id)->orderBy('id'),
                ['post_id', 'body', 'created_at'],
            ),
            'post_reactions' => self::rows(
                DB::table('post_reactions')->where('user_id', $id)->orderBy('id'),
                ['post_id', 'emoji', 'created_at'],
            ),
            'reviews' => self::rows(
                DB::table('restaurant_reviews as v')
                    ->join('users as r', 'r.id', '=', 'v.restaurant_id')
                    ->where('v.user_id', $id)
                    ->orderBy('v.id'),
                ['r.name as restaurant', 'v.rating', 'v.comment', 'v.created_at'],
            ),
            'personal_coupons' => self::rows(
                DB::table('coupons')->where('customer_id', $id)->orderBy('id'),
                ['code', 'discount_type', 'discount_value', 'max_discount', 'expires_at', 'created_at'],
            ),
            'loyalty_rewards' => self::rows(
                DB::table('loyalty_rewards as l')
                    ->join('users as r', 'r.id', '=', 'l.restaurant_id')
                    ->where('l.user_id', $id)
                    ->orderBy('l.id'),
                ['r.name as restaurant', 'l.earned_on', 'l.created_at'],
            ),
        ];
    }

    /**
     * @param  array<int, string>  $columns
     * @return Collection<int, array<mixed>>
     */
    private static function rows(Builder $query, array $columns): Collection
    {
        return $query->get($columns)->map(fn (object $row): array => get_object_vars($row));
    }
}
