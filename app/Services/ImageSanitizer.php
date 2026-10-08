<?php

namespace App\Services;

use Spatie\Image\Image;
use Throwable;

final class ImageSanitizer
{
    /**
     * Re-encode an image so a payload hidden in the original bytes cannot survive.
     */
    public function reencode(string $sourcePath, string $extension): string
    {
        $target = tempnam(sys_get_temp_dir(), 'media-img');

        if ($target === false) {
            throw new \RuntimeException('Unable to prepare a sanitized image.');
        }

        $destination = $target.'.'.$extension;
        @unlink($target);

        try {
            Image::load($sourcePath)->save($destination);
        } catch (Throwable $exception) {
            @unlink($destination);

            throw new \RuntimeException('This image could not be sanitized.', 0, $exception);
        }

        if (! is_file($destination) || filesize($destination) < 1) {
            @unlink($destination);

            throw new \RuntimeException('This image could not be sanitized.');
        }

        return $destination;
    }
}
