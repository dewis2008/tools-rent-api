<?php

namespace Modules\Payments\Services;

use Illuminate\Validation\ValidationException;
use Modules\Payments\Data\PaymentRefundResult;
use Modules\Payments\Jobs\ProcessPaymentRefund;
use Modules\Payments\Models\Payment;
use Throwable;

class PaymentRefundService
{
    public function __construct(
        private StripePaymentService $stripe,
    ) {}

    public function refund(Payment $payment): PaymentRefundResult
    {
        if ($payment->provider === 'stripe') {
            return $this->stripe->createRefund($payment);
        }

        if (in_array($payment->provider, ['demo', 'manual'], true)) {
            return new PaymentRefundResult('refunded');
        }

        throw ValidationException::withMessages([
            'status' => __('The payment provider does not support automatic refunds.'),
        ]);
    }

    public function schedule(Payment $payment): void
    {
        ProcessPaymentRefund::dispatch($payment->id)->afterCommit();
    }

    public function synchronizePendingStripeRefunds(int $limit = 100): int
    {
        return Payment::query()
            ->where('provider', 'stripe')
            ->where('status', 'refund_pending')
            ->whereNotNull('provider_refund_id')
            ->oldest('id')
            ->limit($limit)
            ->get()
            ->filter(function (Payment $payment): bool {
                try {
                    $result = $this->stripe->retrieveRefund($payment->provider_refund_id);
                } catch (Throwable $exception) {
                    report($exception);

                    return false;
                }

                if ($result->paymentStatus === 'refund_pending') {
                    return false;
                }

                return Payment::query()
                    ->whereKey($payment->id)
                    ->where('status', 'refund_pending')
                    ->update($result->paymentUpdates()) === 1;
            })
            ->count();
    }
}
