<?php

/*
|--------------------------------------------------------------------------
| Online payments (Stripe Connect)
|--------------------------------------------------------------------------
|
| Customers pay their table through Stripe Checkout. The charge is made
| directly on the restaurant's own Stripe account (so the money is theirs
| and Stripe's processing fee comes out of it), and the platform keeps an
| application fee set by the restaurant's tier (App\Models\FeeTier, managed in
| the admin panel) or by a commission the admin set for that restaurant.
| Changing a percentage only affects payments started after the change:
| each payment stores the rate it was charged with.
|
*/

return [

    'currency' => 'brl',

    // Tier given to restaurants that were never assigned one (a fee_tiers key; it can't be deleted).
    'default_tier' => 'default',

    // Stripe's minimum charge in BRL is R$ 0,50; a coupon never brings a charge below it.
    'min_charge_cents' => 50,

    // How long a customer has to finish paying on Stripe. While the checkout is
    // open its amount is reserved, so two people can't pay the same remaining balance.
    // Stripe accepts 30 minutes to 24 hours.
    'checkout_minutes' => 30,

];
