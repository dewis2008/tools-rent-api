<?php

namespace Modules\Payments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Payments\Models\Payment;
use Modules\Payments\Services\PaymentRefundService;
use Throwable;

class ProcessPaymentRefund implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public int $paymentId,
        public int $refundAttempt,
    ) {}

    public function handle(PaymentRefundService $paymentRefunds): void
    {
        $payment = Payment::query()
            ->whereKey($this->paymentId)
            ->where('status', 'refund_pending')
            ->where('refund_attempts', $this->refundAttempt)
            ->first();

        if (! $payment) {
            return;
        }

        $result = $paymentRefunds->refund($payment);

        Payment::query()
            ->whereKey($payment->id)
            ->where('status', 'refund_pending')
            ->where('refund_attempts', $this->refundAttempt)
            ->update($result->paymentUpdates());
    }

    public function failed(?Throwable $exception): void
    {
        Payment::query()
            ->whereKey($this->paymentId)
            ->where('status', 'refund_pending')
            ->where('refund_attempts', $this->refundAttempt)
            ->update(['status' => 'refund_failed']);
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }
}
