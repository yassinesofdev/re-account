<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Free organization plans
    |--------------------------------------------------------------------------
    |
    | Plans are not defined by seat numbers: an org's seat limit for a product
    | is the quantity it bought (min 2). Free org plans (price 0) still need a
    | cap to prevent abuse; this one global value applies to all of them.
    |
    */

    'free_org_max_seats' => (int) env('FREE_ORG_MAX_SEATS', 3),

];
