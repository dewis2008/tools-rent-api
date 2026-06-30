<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        $targetDiskName = (string) config('toolimages.disk', 'local');

        if ($targetDiskName === 'public') {
            return;
        }

        $publicDisk = Storage::disk('public');
        $targetDisk = Storage::disk($targetDiskName);

        DB::table('tool_images')
            ->whereNotNull('image_path')
            ->select(['id', 'image_path'])
            ->chunkById(100, function ($images) use ($publicDisk, $targetDisk): void {
                foreach ($images as $image) {
                    $path = $image->image_path;

                    if (! $targetDisk->exists($path) && $publicDisk->exists($path)) {
                        $stream = $publicDisk->readStream($path);

                        if (! is_resource($stream)) {
                            throw new RuntimeException("Tool image [{$path}] could not be read from public storage.");
                        }

                        try {
                            if (! $targetDisk->writeStream($path, $stream)) {
                                throw new RuntimeException("Tool image [{$path}] could not be written to private storage.");
                            }
                        } finally {
                            fclose($stream);
                        }
                    }

                    if ($publicDisk->exists($path) && ! $publicDisk->delete($path)) {
                        throw new RuntimeException("Tool image [{$path}] could not be removed from public storage.");
                    }
                }
            });
    }
};
