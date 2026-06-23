<?php

namespace Modules\ToolImages\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\ToolImages\Models\ToolImage;
use RuntimeException;
use Throwable;

class ToolImageService
{
    public function store(array $attributes): ToolImage
    {
        $image = $attributes['image'];
        unset($attributes['image']);

        $path = $this->storeImage($image, (int) $attributes['tool_id']);

        try {
            return DB::transaction(function () use ($attributes, $path): ToolImage {
                if ($attributes['is_main'] ?? false) {
                    $this->clearMainImage((int) $attributes['tool_id']);
                }

                return ToolImage::create([
                    ...$attributes,
                    'image_path' => $path,
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($path);

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

        try {
            $toolImage = DB::transaction(function () use ($toolImage, $attributes, $toolId, $newPath, $shouldBeMain): ToolImage {
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
                Storage::disk('public')->delete($newPath);
            }

            throw $exception;
        }

        if ($newPath && $oldPath !== $newPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return $toolImage;
    }

    public function delete(ToolImage $toolImage): void
    {
        $path = $toolImage->image_path;

        DB::transaction(function () use ($toolImage): void {
            $toolImage->delete();
        });

        Storage::disk('public')->delete($path);
    }

    private function storeImage(UploadedFile $image, int $toolId): string
    {
        $path = $image->store("tool-images/{$toolId}", 'public');

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
}
