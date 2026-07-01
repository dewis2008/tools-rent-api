<?php

namespace Modules\ToolImages\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\ToolImages\Models\PendingToolImageFileDeletion;
use Modules\ToolImages\Models\ToolImage;
use Modules\Tools\Models\Tool;
use Modules\Vendors\Models\VendorProfile;
use RuntimeException;
use Throwable;

class ToolImageService
{
    public function store(array $attributes): ToolImage
    {
        $image = $attributes['image'];
        unset($attributes['image']);

        $path = $this->storeImage($image, (int) $attributes['tool_id']);
        $this->deleteFileAfterRollback($path);

        try {
            return $this->withinTransaction(function () use ($attributes, $path): ToolImage {
                $this->deleteFileAfterRollback($path);
                $toolId = (int) $attributes['tool_id'];
                $lockedTools = $this->lockVendorToolsFor([$toolId]);
                $targetTool = $lockedTools->firstWhere('id', $toolId);

                $this->ensureImageQuotaAvailable($targetTool, $lockedTools, 'image');

                if ($attributes['is_main'] ?? false) {
                    $this->clearMainImage($toolId);
                }

                return ToolImage::create([
                    ...$attributes,
                    'image_path' => $path,
                ]);
            });
        } catch (Throwable $exception) {
            $this->deleteOrQueueFile($path);

            throw $exception;
        }
    }

    public function update(ToolImage $toolImage, array $attributes): ToolImage
    {
        $image = $attributes['image'] ?? null;
        unset($attributes['image'], $attributes['image_path']);

        $toolId = (int) ($attributes['tool_id'] ?? $toolImage->tool_id);
        $oldPath = $toolImage->image_path;
        $shouldBeMain = array_key_exists('is_main', $attributes)
            ? (bool) $attributes['is_main']
            : $toolImage->is_main;
        $newPath = $image instanceof UploadedFile
            ? $this->storeImage($image, $toolId)
            : null;

        if ($newPath) {
            $this->deleteFileAfterRollback($newPath);
        }

        try {
            $toolImage = $this->withinTransaction(function () use ($toolImage, $attributes, $toolId, $newPath, $oldPath, $shouldBeMain): ToolImage {
                $sourceToolId = (int) $toolImage->tool_id;
                $lockedTools = $this->lockVendorToolsFor([$sourceToolId, $toolId]);
                $toolImage = ToolImage::query()->lockForUpdate()->findOrFail($toolImage->id);

                if ((int) $toolImage->tool_id !== $sourceToolId) {
                    throw ValidationException::withMessages([
                        'tool_id' => __('The tool image changed while the request was being processed. Please try again.'),
                    ]);
                }

                if ($sourceToolId !== $toolId) {
                    $targetTool = $lockedTools->firstWhere('id', $toolId);
                    $sourceTool = $lockedTools->firstWhere('id', $sourceToolId);
                    $quotaField = array_key_exists('tool_id', $attributes) ? 'tool_id' : 'image';

                    $this->ensureImageQuotaAvailable(
                        $targetTool,
                        $lockedTools,
                        $quotaField,
                        $sourceTool->vendor_id === $targetTool->vendor_id,
                    );
                }

                if ($newPath) {
                    $this->deleteFileAfterRollback($newPath);
                    $this->queueFileDeletion($oldPath);
                    $this->processPendingDeletionsAfterCommit();
                }

                if ($shouldBeMain) {
                    $this->clearMainImage($toolId, $toolImage->id);
                }

                $toolImage->update([
                    ...$attributes,
                    ...($newPath ? ['image_path' => $newPath] : []),
                ]);

                return $toolImage;
            });
        } catch (Throwable $exception) {
            if ($newPath) {
                $this->deleteOrQueueFile($newPath);
            }

            throw $exception;
        }

        return $toolImage;
    }

    public function delete(ToolImage $toolImage): void
    {
        $this->withinTransaction(function () use ($toolImage): void {
            $this->queueFileDeletion($toolImage->image_path);
            $toolImage->delete();
            $this->processPendingDeletionsAfterCommit();
        });
    }

    public function deleteFilesForTool(Tool $tool): void
    {
        $this->deleteImageFiles(
            ToolImage::query()->where('tool_id', $tool->id),
        );
    }

    public function deleteFilesForVendorProfile(VendorProfile $vendorProfile): void
    {
        $this->deleteImageFiles(
            ToolImage::query()
                ->whereHas('tool', fn (Builder $query) => $query->where('vendor_id', $vendorProfile->id)),
        );
    }

    public function deleteFilesForUser(User $user): void
    {
        $this->deleteImageFiles(
            ToolImage::query()
                ->whereHas('tool.vendor', fn (Builder $query) => $query->where('user_id', $user->id)),
        );
    }

    public function processPendingDeletionsAfterCommit(): void
    {
        DB::afterCommit(function (): void {
            try {
                $this->processPendingDeletions();
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function processPendingDeletions(int $limit = 100): int
    {
        return PendingToolImageFileDeletion::query()
            ->oldest('id')
            ->limit($limit)
            ->get()
            ->filter(fn (PendingToolImageFileDeletion $deletion) => $this->processPendingDeletion($deletion))
            ->count();
    }

    private function storeImage(UploadedFile $image, int $toolId): string
    {
        $path = $image->store("tool-images/{$toolId}", $this->disk());

        if (! $path) {
            throw new RuntimeException('Tool image could not be stored.');
        }

        return $path;
    }

    private function clearMainImage(int $toolId, ?int $exceptId = null): void
    {
        ToolImage::query()
            ->where('tool_id', $toolId)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['is_main' => false]);
    }

    /**
     * @param  array<int, int>  $toolIds
     * @return Collection<int, Tool>
     */
    private function lockVendorToolsFor(array $toolIds): Collection
    {
        $requestedTools = Tool::query()
            ->whereKey($toolIds)
            ->get(['id', 'vendor_id']);

        if ($requestedTools->count() !== count(array_unique($toolIds))) {
            collect($toolIds)
                ->unique()
                ->each(fn (int $toolId) => Tool::query()->findOrFail($toolId));
        }

        $lockedTools = Tool::query()
            ->whereIn('vendor_id', $requestedTools->pluck('vendor_id')->unique())
            ->orderBy('vendor_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'vendor_id']);

        $requestedTools->each(function (Tool $requestedTool) use ($lockedTools): void {
            $lockedTool = $lockedTools->firstWhere('id', $requestedTool->id);

            if ($lockedTool?->vendor_id !== $requestedTool->vendor_id) {
                throw ValidationException::withMessages([
                    'tool_id' => __('The selected tool changed while the request was being processed. Please try again.'),
                ]);
            }
        });

        return $lockedTools;
    }

    /**
     * @param  Collection<int, Tool>  $lockedTools
     */
    private function ensureImageQuotaAvailable(
        Tool $targetTool,
        Collection $lockedTools,
        string $errorField,
        bool $sameVendorMove = false,
    ): void {
        $maxImagesPerTool = (int) config('toolimages.max_per_tool', 10);
        $toolImageCount = ToolImage::query()
            ->where('tool_id', $targetTool->id)
            ->lockForUpdate()
            ->pluck('id')
            ->count();

        if ($toolImageCount >= $maxImagesPerTool) {
            throw ValidationException::withMessages([
                $errorField => __('The selected tool may have at most :count images.', [
                    'count' => $maxImagesPerTool,
                ]),
            ]);
        }

        if ($sameVendorMove) {
            return;
        }

        $vendorToolIds = $lockedTools
            ->where('vendor_id', $targetTool->vendor_id)
            ->pluck('id');
        $vendorImageCount = ToolImage::query()
            ->whereIn('tool_id', $vendorToolIds)
            ->lockForUpdate()
            ->pluck('id')
            ->count();
        $maxImagesPerVendor = (int) config('toolimages.max_per_vendor', 100);

        if ($vendorImageCount >= $maxImagesPerVendor) {
            throw ValidationException::withMessages([
                $errorField => __('A vendor may have at most :count tool images.', [
                    'count' => $maxImagesPerVendor,
                ]),
            ]);
        }
    }

    private function deleteImageFiles(Builder $query): void
    {
        $query
            ->pluck('image_path')
            ->filter()
            ->unique()
            ->each(fn (string $path) => $this->queueFileDeletion($path));
    }

    private function withinTransaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }

    private function deleteFileAfterRollback(string $path): void
    {
        DB::connection()->afterRollBack(fn () => $this->deleteOrQueueFile($path));
    }

    private function deleteOrQueueFile(string $path): void
    {
        try {
            if (Storage::disk($this->disk())->delete($path)) {
                return;
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->queueFileDeletion($path);
    }

    private function queueFileDeletion(string $path): void
    {
        PendingToolImageFileDeletion::query()->firstOrCreate([
            'image_path' => $path,
        ]);
    }

    private function processPendingDeletion(PendingToolImageFileDeletion $deletion): bool
    {
        if (ToolImage::query()->where('image_path', $deletion->image_path)->exists()) {
            $deletion->delete();

            return false;
        }

        try {
            if (! Storage::disk($this->disk())->delete($deletion->image_path)) {
                throw new RuntimeException('Tool image file could not be deleted.');
            }

            $deletion->delete();

            return true;
        } catch (Throwable $exception) {
            $deletion->update([
                'attempts' => $deletion->attempts + 1,
                'last_attempted_at' => now(),
                'last_error' => $exception->getMessage(),
            ]);

            report($exception);

            return false;
        }
    }

    private function disk(): string
    {
        return (string) config('toolimages.disk', 'local');
    }
}
