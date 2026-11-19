<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManagesCoupons;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Platform coupons: valid at every restaurant, the discount is paid by the platform. */
class CouponController extends Controller
{
    use ManagesCoupons;

    protected function couponOwnerId(Request $request): ?int
    {
        return null;
    }

    protected function couponPage(): string
    {
        return 'admin/coupons';
    }

    protected function couponIndexRoute(): string
    {
        return 'admin.coupons.index';
    }
}
