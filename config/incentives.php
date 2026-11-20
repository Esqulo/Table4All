<?php

return [

    /*
    | Loyalty card: every visit to a place (at most one per local day) fills a
    | segment. A visit counts once the table the customer sat at is closed with
    | something on the bill. Staying away longer than `streak_days` loses the
    | whole streak.
    |
    | Each restaurant sets its own ladder of tiers (visits → coupon), counted
    | only while it has the incentive on; the coupon is paid by the restaurant.
    | The bar runs up to the last tier and then starts over. Restaurants that
    | never set one use `default_tiers`.
    */
    'loyalty' => [
        'streak_days' => 30,
        'reward_valid_days' => 30,
        'max_tiers' => 5,
        'max_visits' => 100,
        'default_tiers' => [
            ['visits' => 3, 'discount_type' => 'fixed', 'discount_value' => 10.00, 'max_discount' => null],
        ],
    ],

];
