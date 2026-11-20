<?php

namespace App\Http\Requests;

use App\Enums\CouponDiscountType;
use App\Models\Coupon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A coupon as the restaurant (or the admin, for platform coupons) fills it in.
 * Codes are unique per owner.
 */
class CouponRequest extends FormRequest
{
    /** The restaurant that owns the coupon; null for the platform's (admin routes). */
    public function ownerId(): ?int
    {
        return $this->routeIs('admin.*') ? null : $this->user()?->id;
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
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
}
