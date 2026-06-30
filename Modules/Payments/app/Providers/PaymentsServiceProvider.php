<?php

namespace Modules\Payments\Providers;

use Illuminate\Console\Scheduling\Schedule;
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

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule
            ->command(SyncPendingStripeRefundsCommand::class)
            ->everyMinute()
            ->withoutOverlapping();
    }
}
