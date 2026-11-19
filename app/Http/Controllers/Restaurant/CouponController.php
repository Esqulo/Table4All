<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Concerns\ManagesCoupons;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** The restaurant's own coupons: valid only at its tables, discount paid by it. */
class CouponController extends Controller
{
    use ManagesCoupons;

    protected function couponOwnerId(Request $request): ?int
    {
        return $request->user()->id;
    }

    protected function couponPage(): string
    {
        return 'restaurant/coupons';
    }

    protected function couponIndexRoute(): string
    {
        return 'restaurant.coupons.index';
    }
}
