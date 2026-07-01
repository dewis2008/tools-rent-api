<?php

namespace Modules\Payments\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Payments\Console\SyncPendingStripeRefundsCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;

class PaymentsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Payments';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'payments';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        SyncPendingStripeRefundsCommand::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        RateLimiter::for('stripe-payment-intents', function (Request $request): Limit {
            return Limit::perMinute((int) config('payments.stripe_payment_intents_per_minute', 10))
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        });
    }

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule
            ->command(SyncPendingStripeRefundsCommand::class)
            ->everyMinute()
            ->withoutOverlapping();
    }
}
