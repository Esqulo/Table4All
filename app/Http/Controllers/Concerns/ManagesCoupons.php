<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\OnlinePaymentStatus;
use App\Http\Requests\CouponRequest;
use App\Models\Coupon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Coupon CRUD shared by restaurants (their own coupons, discount paid by them)
 * and the admin (platform coupons, valid everywhere, paid by the platform).
 */
trait ManagesCoupons
{
    /** The owner of the coupons this controller manages; null for the platform. */
    abstract protected function couponOwnerId(Request $request): ?int;

    abstract protected function couponPage(): string;

    abstract protected function couponIndexRoute(): string;

    public function index(Request $request): Response
    {
        $ownerId = $this->couponOwnerId($request);
        $timezone = (string) config('app.business_timezone');

        $coupons = Coupon::query()
            ->when($ownerId === null, fn ($q) => $q->whereNull('user_id'), fn ($q) => $q->where('user_id', $ownerId))
            // Loyalty rewards are handed out automatically, not managed here.
            ->notLoyaltyReward()
            ->with('customer:id,name')
            ->withCount(['onlinePayments as uses' => fn ($q) => $q->where('status', OnlinePaymentStatus::PAID->value)])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Coupon $coupon) => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'discount_type' => $coupon->discount_type->value,
                'discount_value' => $coupon->discount_value,
                'max_discount' => $coupon->max_discount,
                'expires_on' => $coupon->expires_at?->setTimezone($timezone)->format('Y-m-d'),
                'expired' => $coupon->isExpired(),
                'multiple_per_table' => $coupon->multiple_per_table,
                'max_uses' => $coupon->max_uses,
                'uses' => (int) $coupon->getAttribute('uses'),
                'exhausted' => $coupon->max_uses !== null && (int) $coupon->getAttribute('uses') >= $coupon->max_uses,
                // Who it is restricted to, by name only: staff never see a customer's e-mail.
                'customer' => $coupon->customer_id === null ? null : ['name' => $coupon->customer?->name],
            ]);

        return Inertia::render($this->couponPage(), [
            'coupons' => $coupons,
        ]);
    }

    public function store(CouponRequest $request): RedirectResponse
    {
        Coupon::create([
            ...$this->couponAttributes($request),
            'user_id' => $this->couponOwnerId($request),
            // Set only on creation; null means anyone may use it.
            'customer_id' => $request->customerId(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'coupons.msg_created']);

        return to_route($this->couponIndexRoute());
    }

    public function update(CouponRequest $request, Coupon $coupon): RedirectResponse
    {
        $this->authorizeCoupon($request, $coupon);

        $coupon->update($this->couponAttributes($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'coupons.msg_updated']);

        return to_route($this->couponIndexRoute());
    }

    public function destroy(Request $request, Coupon $coupon): RedirectResponse
    {
        $this->authorizeCoupon($request, $coupon);

        // Soft delete: payments that used it keep pointing at it.
        $coupon->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'coupons.msg_deleted']);

        return to_route($this->couponIndexRoute());
    }

    private function authorizeCoupon(Request $request, Coupon $coupon): void
    {
        abort_unless($coupon->user_id === $this->couponOwnerId($request), 403);
        // A customer's loyalty reward isn't the restaurant's to edit or take back.
        abort_if($coupon->customer_id !== null && $coupon->isLoyaltyReward(), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function couponAttributes(CouponRequest $request): array
    {
        $data = $request->validated();
        $expiresOn = $data['expires_on'] ?? null;

        return [
            'code' => $data['code'],
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'max_discount' => $data['max_discount'] ?? null,
            // Valid through the whole chosen day, in the venue's time.
            'expires_at' => is_string($expiresOn)
                ? Carbon::createFromFormat('Y-m-d', $expiresOn, (string) config('app.business_timezone'))?->endOfDay()->utc()
                : null,
            'max_uses' => isset($data['max_uses']) ? (int) $data['max_uses'] : null,
            'multiple_per_table' => (bool) $data['multiple_per_table'],
        ];
    }
}
