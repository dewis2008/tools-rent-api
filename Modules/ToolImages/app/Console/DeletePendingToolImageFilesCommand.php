<?php

namespace Modules\ToolImages\Console;

use Illuminate\Console\Command;
use Modules\ToolImages\Services\ToolImageService;

class DeletePendingToolImageFilesCommand extends Command
{
    protected $signature = 'tool-images:delete-pending-files {--limit=100 : Maximum files to process}';

    protected $description = 'Delete tool image files queued after database changes';

    public function handle(ToolImageService $toolImages): int
    {
        $deletedCount = $toolImages->processPendingDeletions((int) $this->option('limit'));

        $this->comment("Deleted {$deletedCount} pending tool image files.");

        return self::SUCCESS;
    }
}
