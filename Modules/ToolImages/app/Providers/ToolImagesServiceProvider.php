<?php

namespace Modules\ToolImages\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

    public function boot(): void
    {
        parent::boot();

        RateLimiter::for('tool-image-uploads', function (Request $request): Limit {
            return Limit::perMinute((int) config('toolimages.uploads_per_minute', 10))
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        });
    }

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
