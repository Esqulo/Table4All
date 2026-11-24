<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Enums\CouponDiscountType;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A coupon as the restaurant (or the admin, for platform coupons) fills it in.
 * Codes are unique per owner. On creation it may be tied to one customer by
 * their account e-mail: then only that customer can use it.
 */
class CouponRequest extends FormRequest
{
    /** The customer the new coupon is restricted to, found by the e-mail typed. */
    private ?int $customerId = null;

    public function customerId(): ?int
    {
        return $this->customerId;
    }

    private function isCreating(): bool
    {
        return ! $this->route('coupon') instanceof Coupon;
    }

    /** The restaurant that owns the coupon; null for the platform's (admin routes). */
    public function ownerId(): ?int
    {
        // The restaurant's id for its staff too (effectiveRestaurantId), as the controller stores it.
        return $this->routeIs('admin.*') ? null : $this->user()?->effectiveRestaurantId();
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Coupon::normalizeCode($this->input('code'))]);
        }

        $this->merge(['multiple_per_table' => $this->boolean('multiple_per_table')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isPercent = $this->input('discount_type') === CouponDiscountType::PERCENT->value;

        return [
            'code' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[A-Z0-9_-]+$/'],
            'discount_type' => ['required', Rule::enum(CouponDiscountType::class)],
            'discount_value' => ['required', 'numeric', 'gt:0', $isPercent ? 'max:100' : 'max:99999.99'],
            'max_discount' => ['nullable', 'numeric', 'gt:0', 'max:99999.99'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            // Total uses across every table and customer; empty for unlimited.
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'multiple_per_table' => ['boolean'],
            // Only when creating: who a coupon belongs to doesn't change afterwards.
            'customer_email' => $this->isCreating() ? ['nullable', 'string', 'email', 'max:255'] : ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->resolveCustomer($validator);

            if ($validator->errors()->has('code')) {
                return;
            }

            $coupon = $this->route('coupon');
            $ownerId = $this->ownerId();
            $taken = Coupon::where('code', $this->input('code'))
                ->when(
                    $ownerId === null,
                    fn ($q) => $q->whereNull('user_id'),
                    fn ($q) => $q->where('user_id', $ownerId),
                )
                ->when($coupon instanceof Coupon, fn ($q) => $q->whereKeyNot($coupon instanceof Coupon ? $coupon->id : null))
                ->exists();

            if ($taken) {
                $validator->errors()->add('code', __('coupons.code_taken'));
            }
        });
    }

    private function resolveCustomer(Validator $validator): void
    {
        $email = $this->input('customer_email');
        if (! $this->isCreating() || ! is_string($email) || trim($email) === '' || $validator->errors()->has('customer_email')) {
            return;
        }

        $email = trim($email);
        // Sign-up stores e-mails lower-cased; looking up both spellings keeps the unique index in use.
        $customerId = User::whereIn('email', array_unique([$email, Str::lower($email)]))
            ->where('account_type', AccountType::CUSTOMER->value)
            ->whereNull('deactivated_at')
            ->value('id');

        if ($customerId === null) {
            $validator->errors()->add('customer_email', __('coupons.customer_not_found'));

            return;
        }

        $this->customerId = (int) $customerId;
    }
}
