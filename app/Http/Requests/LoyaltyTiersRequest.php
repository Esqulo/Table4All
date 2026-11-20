<?php

namespace App\Http\Requests;

use App\Enums\CouponDiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The restaurant's loyalty ladder: tiers of "after N visits, a coupon with this
 * discount", with the same discount options as its coupons.
 */
class LoyaltyTiersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'tiers' => ['required', 'array', 'min:1', 'max:'.(int) config('incentives.loyalty.max_tiers')],
            'tiers.*' => ['array:visits,discount_type,discount_value,max_discount'],
            'tiers.*.visits' => ['required', 'integer', 'min:1', 'max:'.(int) config('incentives.loyalty.max_visits'), 'distinct'],
            'tiers.*.discount_type' => ['required', Rule::enum(CouponDiscountType::class)],
            'tiers.*.discount_value' => ['required', 'numeric', 'gt:0', 'max:99999.99'],
            'tiers.*.max_discount' => ['nullable', 'numeric', 'gt:0', 'max:99999.99'],
        ];

        // A percentage can't go past 100.
        foreach ((array) $this->input('tiers', []) as $i => $tier) {
            if (is_array($tier) && ($tier['discount_type'] ?? null) === CouponDiscountType::PERCENT->value) {
                $rules["tiers.{$i}.discount_value"] = ['required', 'numeric', 'gt:0', 'max:100'];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tiers' => __('loyalty.attribute_tiers'),
            'tiers.*.visits' => __('loyalty.attribute_visits'),
            'tiers.*.discount_type' => __('loyalty.attribute_discount_type'),
            'tiers.*.discount_value' => __('loyalty.attribute_discount_value'),
            'tiers.*.max_discount' => __('loyalty.attribute_max_discount'),
        ];
    }

    /**
     * The validated tiers, ready to store. A cap only means something on a
     * percentage, so fixed discounts drop it.
     *
     * @return list<array{visits: int, discount_type: string, discount_value: float, max_discount: float|null}>
     */
    public function tiers(): array
    {
        /** @var list<array<string, mixed>> $tiers */
        $tiers = $this->validated('tiers');

        return array_map(function (array $tier) {
            $isPercent = $tier['discount_type'] === CouponDiscountType::PERCENT->value;
            $max = $tier['max_discount'] ?? null;

            return [
                'visits' => (int) $tier['visits'],
                'discount_type' => (string) $tier['discount_type'],
                'discount_value' => (float) $tier['discount_value'],
                'max_discount' => $isPercent && is_numeric($max) ? (float) $max : null,
            ];
        }, $tiers);
    }
}
