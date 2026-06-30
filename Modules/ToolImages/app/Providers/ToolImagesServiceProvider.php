<?php

namespace Modules\ToolImages\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\ToolImages\Console\DeletePendingToolImageFilesCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ToolImagesServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'ToolImages';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'toolimages';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        DeletePendingToolImageFilesCommand::class,
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

    /**
     * Define module schedules.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule
            ->command(DeletePendingToolImageFilesCommand::class)
            ->everyMinute()
            ->withoutOverlapping();
    }
}
