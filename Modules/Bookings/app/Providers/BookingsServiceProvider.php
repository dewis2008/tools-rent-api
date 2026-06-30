<?php

namespace Modules\Bookings\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Bookings\Console\ExpirePendingBookingsCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;

class BookingsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Bookings';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'bookings';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        ExpirePendingBookingsCommand::class,
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
            ->command(ExpirePendingBookingsCommand::class)
            ->everyMinute()
            ->withoutOverlapping();
    }
}
