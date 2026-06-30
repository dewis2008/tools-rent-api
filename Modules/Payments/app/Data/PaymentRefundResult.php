<?php

namespace Modules\Payments\Data;

class PaymentRefundResult
{
    public function __construct(
        public string $paymentStatus,
        public ?string $providerRefundId = null,
    ) {}

    public function paymentUpdates(): array
    {
        return [
            'status' => $this->paymentStatus,
            ...($this->providerRefundId ? ['provider_refund_id' => $this->providerRefundId] : []),
        ];
    }
}
