<?php

namespace App\Enums;

/** Customer incentives a restaurant can switch on or off (restaurant "Incentivos" page). */
enum Incentive: string
{
    /** Loyalty card: a coupon every few visits, paid by the restaurant. */
    case LOYALTY = 'loyalty';
}
