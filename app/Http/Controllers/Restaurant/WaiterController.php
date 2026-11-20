<?php

namespace App\Http\Controllers\Restaurant;

use App\Enums\AccountType;
use App\Enums\Permission;
use App\Enums\StaffFeature;
use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Mail\WaiterInvitationMail;
use App\Models\User;
use App\Models\WaiterInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The restaurant's team: invitations, each member's role and access per feature, removal.
 *
 * The owner manages everyone. A manager manages waiters only: never managers (themselves
 * included), never makes someone a manager, and can't grant more than they have.
 */
class WaiterController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $request->user();
        $restaurantId = $actor->effectiveRestaurantId();

        $members = User::where('restaurant_id', $restaurantId)
            ->where('account_type', AccountType::WAITER)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'staff_role', 'staff_permissions', 'restaurant_id', 'account_type', 'created_at'])
            ->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->staffRole()->value,
                // What was chosen per feature (the editor's values)…
                'permissions' => $this->granted($member),
                // …and what it amounts to with the role (a manager reads everything).
                'access' => $member->accessMap(),
                'manageable' => $this->canManage($actor, $member),
                'is_self' => $member->id === $actor->id,
                'created_at' => $member->created_at,
            ]);

        $invitations = WaiterInvitation::where('restaurant_id', $restaurantId)
            ->pending()
            ->orderByDesc('created_at')
            ->get(['id', 'email', 'role', 'created_at'])
            ->map(fn (WaiterInvitation $i): array => [
                'id' => $i->id,
                'email' => $i->email,
                'role' => $i->role,
                'cancellable' => $this->isOwner($actor) || $i->role === StaffRole::WAITER->value,
                'created_at' => $i->created_at,
            ]);

        return Inertia::render('restaurant/waiters', [
            'members' => $members,
            'invitations' => $invitations,
            'features' => array_map(fn (StaffFeature $f): array => [
                'key' => $f->value,
                'ceiling' => $f->staffCeiling()->value,
            ], StaffFeature::grantableCases()),
            // The most this account may grant per feature, and whether it may name managers.
            'grantLimits' => $this->grantLimits($actor),
            'canManageManagers' => $this->isOwner($actor),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['sometimes', Rule::enum(StaffRole::class)],
        ]);

        $role = StaffRole::tryFrom($validated['role'] ?? '') ?? StaffRole::WAITER;
        abort_if($role === StaffRole::MANAGER && ! $this->isOwner($actor), 403);

        $restaurantId = $actor->effectiveRestaurantId();
        $email = $validated['email'];

        if (User::where('email', $email)
            ->where('restaurant_id', $restaurantId)
            ->where('account_type', AccountType::WAITER)
            ->exists()) {
            return back()->withErrors(['email' => __('waiters.error_already_waiter')]);
        }

        // Remove any previous pending invite for this email at this restaurant
        WaiterInvitation::where('restaurant_id', $restaurantId)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->delete();

        $invitation = WaiterInvitation::create([
            'restaurant_id' => $restaurantId,
            'email' => $email,
            'role' => $role->value,
            'token' => Str::random(64),
        ]);

        Mail::to($email)->send(new WaiterInvitationMail($invitation, $actor->restaurantAccount()));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'waiters.msg_invited']);

        return to_route('restaurant.waiters.index');
    }

    /** Role and access per feature of one member; the whole set is replaced. */
    public function update(Request $request, User $waiter): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureMember($actor, $waiter);
        abort_unless($this->canManage($actor, $waiter), 403);

        $levels = array_map(fn (Permission $p): string => $p->value, Permission::cases());
        $rules = ['role' => ['required', Rule::enum(StaffRole::class)], 'permissions' => ['required', 'array']];

        foreach (StaffFeature::grantableCases() as $feature) {
            $rules['permissions.'.$feature->value] = ['required', Rule::in($levels)];
        }

        /** @var array{role: string, permissions: array<string, string>} $data */
        $data = $request->validate($rules);
        $role = StaffRole::from($data['role']);
        abort_if($role === StaffRole::MANAGER && ! $this->isOwner($actor), 403);

        $limits = $this->grantLimits($actor);
        $permissions = [];

        foreach (StaffFeature::grantableCases() as $feature) {
            $level = Permission::from($data['permissions'][$feature->value]);

            if (! Permission::from($limits[$feature->value])->allows($level)) {
                throw ValidationException::withMessages([
                    'permissions.'.$feature->value => __('staff.error_above_limit'),
                ]);
            }

            $permissions[$feature->value] = $level->value;
        }

        $waiter->forceFill(['staff_role' => $role, 'staff_permissions' => $permissions])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'waiters.msg_permissions_saved']);

        return to_route('restaurant.waiters.index');
    }

    public function cancelInvitation(Request $request, WaiterInvitation $invitation): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($invitation->restaurant_id === $actor->effectiveRestaurantId(), 403);
        abort_unless($this->isOwner($actor) || $invitation->role === StaffRole::WAITER->value, 403);

        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'waiters.msg_invite_cancelled']);

        return to_route('restaurant.waiters.index');
    }

    public function destroy(Request $request, User $waiter): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureMember($actor, $waiter);
        abort_unless($this->canManage($actor, $waiter), 403);

        // Back to an ordinary customer account, with nothing left of the job.
        $waiter->forceFill([
            'restaurant_id' => null,
            'account_type' => AccountType::CUSTOMER,
            'staff_role' => null,
            'staff_permissions' => null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'waiters.msg_deleted']);

        return to_route('restaurant.waiters.index');
    }

    private function isOwner(User $actor): bool
    {
        return $actor->account_type === AccountType::RESTAURANT;
    }

    /** A staff account of the actor's restaurant (another restaurant's: 403). */
    private function ensureMember(User $actor, User $member): void
    {
        abort_unless(
            $member->account_type === AccountType::WAITER && $member->restaurant_id === $actor->effectiveRestaurantId(),
            403,
        );
    }

    private function canManage(User $actor, User $member): bool
    {
        if ($this->isOwner($actor)) {
            return true;
        }

        return $actor->isManager()
            && $member->id !== $actor->id
            && $member->staffRole() === StaffRole::WAITER;
    }

    /**
     * The most the actor may grant per feature: the feature's ceiling for staff, and for a
     * manager also their own access.
     *
     * @return array<string, string>
     */
    private function grantLimits(User $actor): array
    {
        $limits = [];

        foreach (StaffFeature::grantableCases() as $feature) {
            $limits[$feature->value] = Permission::min($feature->staffCeiling(), $actor->access($feature))->value;
        }

        return $limits;
    }

    /**
     * Every grantable feature with what was chosen (none when not set).
     *
     * @return array<string, string>
     */
    private function granted(User $member): array
    {
        $chosen = $member->grantedPermissions();
        $result = [];

        foreach (StaffFeature::grantableCases() as $feature) {
            $result[$feature->value] = (Permission::tryFrom((string) ($chosen[$feature->value] ?? '')) ?? Permission::NONE)->value;
        }

        return $result;
    }
}
