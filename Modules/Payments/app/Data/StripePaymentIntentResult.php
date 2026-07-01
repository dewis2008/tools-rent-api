<?php

namespace Modules\Payments\Data;

use Modules\Payments\Models\Payment;

class StripePaymentIntentResult
{
    public function __construct(
        public Payment $payment,
        public string $clientSecret,
    ) {}
}
