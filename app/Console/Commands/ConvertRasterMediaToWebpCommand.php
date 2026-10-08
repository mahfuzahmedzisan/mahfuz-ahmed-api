<?php

namespace App\Console\Commands;

use App\Enums\MediaKind;
use App\Models\MediaItem;
use App\Services\ImageConversionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('media:webp')]
#[Description('Convert stored raster images to optimized WebP. SVG files are left unchanged.')]
class ConvertRasterMediaToWebpCommand extends Command
{
    public function handle(ImageConversionService $images): int
    {
        $converted = 0;
        $failed = 0;

        MediaItem::query()
            ->where('kind', MediaKind::Image)
            ->whereNotIn('extension', ['svg', 'webp'])
            ->orderBy('id')
            ->each(function (MediaItem $item) use ($images, &$converted, &$failed): void {
                $media = $item->getFirstMedia('source');
                $path = $media?->getPath();

                if (! is_string($path) || ! is_file($path)) {
                    $this->components->warn("Media {$item->id} has no source file.");
                    $failed++;

                    return;
                }

                try {
                    $webp = $images->encodeOriginalAsWebp($path);
                    $item->clearMediaCollection('source');
                    $item->addMedia($webp)
                        ->usingFileName(pathinfo((string) $media->file_name, PATHINFO_FILENAME).'.webp')
                        ->toMediaCollection('source');

                    if (is_file($webp)) {
                        @unlink($webp);
                    }

                    $stored = $item->getFirstMedia('source');
                    $item->forceFill([
                        'extension' => 'webp',
                        'mime' => 'image/webp',
                        'size' => $stored?->size ?: $item->size,
                    ])->save();
                    $converted++;
                } catch (Throwable $exception) {
                    $this->components->error("Media {$item->id}: {$exception->getMessage()}");
                    $failed++;
                }
            });

        $this->components->info("Converted {$converted} image(s). {$failed} skipped.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
