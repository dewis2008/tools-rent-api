<?php

namespace Modules\ToolImages\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

                if ($attributes['is_main'] ?? false) {
                    $this->lockTool((int) $attributes['tool_id']);
                    $this->clearMainImage((int) $attributes['tool_id']);
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
                if ($newPath) {
                    $this->deleteFileAfterRollback($newPath);
                    $this->queueFileDeletion($oldPath);
                    $this->processPendingDeletionsAfterCommit();
                }

                if ($shouldBeMain) {
                    $this->lockTool($toolId);
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

    private function lockTool(int $toolId): void
    {
        Tool::query()->lockForUpdate()->findOrFail($toolId);
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
