<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every account on the platform, and switching one off (or back on). A switched-off
 * account can't sign in; for a restaurant, its team can't either and its public profile,
 * tables and menus disappear for customers. Nothing is deleted: bills and payments stay.
 * Admins can't be switched off here.
 */
class UserController extends Controller
{
    public const STATUSES = ['active', 'deactivated'];

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $type = AccountType::tryFrom((string) $request->query('type', ''));
        $status = in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : '';

        $users = User::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('search_name', 'like', '%'.User::searchable($search).'%')
                ->orWhere('email', 'ilike', '%'.$search.'%')))
            ->when($type !== null, fn ($q) => $q->where('account_type', $type->value))
            ->when($status === 'active', fn ($q) => $q->whereNull('deactivated_at'))
            ->when($status === 'deactivated', fn ($q) => $q->whereNotNull('deactivated_at'))
            ->with([
                'restaurant:id,name,deactivated_at',
                'deactivatedBy:id,name',
            ])
            ->orderByDesc('id')
            ->select(['id', 'name', 'email', 'account_type', 'restaurant_id', 'staff_role', 'email_verified_at', 'last_seen_at', 'created_at', 'deactivated_at', 'deactivated_by', 'deactivation_reason'])
            ->paginate(50)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'account_type' => $user->account_type->value,
                'staff_role' => $user->account_type === AccountType::WAITER ? $user->staffRole()->value : null,
                'restaurant' => $user->restaurant ? [
                    'id' => $user->restaurant->id,
                    'name' => $user->restaurant->name,
                    'deactivated' => $user->restaurant->deactivated_at !== null,
                ] : null,
                'email_verified' => $user->email_verified_at !== null,
                'last_seen_at' => $user->last_seen_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'can_deactivate' => $user->canBeDeactivated(),
                ...self::deactivation($user),
            ]);

        return Inertia::render('admin/users', [
            'users' => $users,
            'search' => $search,
            'type' => $type->value ?? '',
            'status' => $status,
            'types' => array_map(fn (AccountType $t) => $t->value, AccountType::cases()),
        ]);
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->canBeDeactivated(), 403);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($request, $user, $data): void {
            // Conditional: a retried or double-clicked request keeps the first date and reason.
            $changed = User::whereKey($user->id)->whereNull('deactivated_at')->update([
                'deactivated_at' => now(),
                'deactivated_by' => $request->user()->id,
                'deactivation_reason' => filled($data['reason'] ?? null) ? trim((string) $data['reason']) : null,
                // "Remember me" cookies stop working; they'd be refused on the next request anyway.
                'remember_token' => null,
            ]);

            if ($changed === 0) {
                return;
            }

            // Signed out everywhere now; a restaurant's team too.
            $ids = [$user->id];
            if ($user->account_type === AccountType::RESTAURANT) {
                $ids = [...$ids, ...User::where('restaurant_id', $user->id)->pluck('id')->all()];
            }
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $user->account_type === AccountType::RESTAURANT
            ? 'admin_users.msg_restaurant_deactivated'
            : 'admin_users.msg_deactivated']);

        return back();
    }

    public function reactivate(User $user): RedirectResponse
    {
        abort_unless($user->canBeDeactivated(), 403);

        User::whereKey($user->id)->whereNotNull('deactivated_at')->update([
            'deactivated_at' => null,
            'deactivated_by' => null,
            'deactivation_reason' => null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'admin_users.msg_reactivated']);

        return back();
    }

    /**
     * How an account was switched off, for the admin screens.
     *
     * @return array{deactivated_at: string|null, deactivated_by: string|null, deactivation_reason: string|null}
     */
    public static function deactivation(User $user): array
    {
        return [
            'deactivated_at' => $user->deactivated_at?->toIso8601String(),
            'deactivated_by' => $user->deactivated_at !== null ? $user->deactivatedBy?->name : null,
            'deactivation_reason' => $user->deactivated_at !== null ? $user->deactivation_reason : null,
        ];
    }
}
