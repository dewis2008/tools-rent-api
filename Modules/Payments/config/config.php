<?php

return [
    'name' => 'Payments',
    'stripe_payment_intents_per_minute' => max((int) env('STRIPE_PAYMENT_INTENTS_PER_MINUTE', 10), 1),
];
