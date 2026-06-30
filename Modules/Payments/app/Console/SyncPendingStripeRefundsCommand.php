<?php

namespace Modules\Payments\Console;

use Illuminate\Console\Command;
use Modules\Payments\Services\PaymentRefundService;

class SyncPendingStripeRefundsCommand extends Command
{
    protected $signature = 'payments:sync-stripe-refunds {--limit=100 : Maximum refunds to synchronize}';

    protected $description = 'Synchronize pending Stripe refund statuses';

    public function handle(PaymentRefundService $paymentRefunds): int
    {
        $updatedCount = $paymentRefunds->synchronizePendingStripeRefunds((int) $this->option('limit'));

        $this->comment("Synchronized {$updatedCount} Stripe refunds.");

        return self::SUCCESS;
    }
}
