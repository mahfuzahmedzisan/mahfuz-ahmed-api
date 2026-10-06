<?php

namespace App\Services;

use Spatie\Image\Enums\Fit;

final readonly class ImageConversionOptions
{
    public function __construct(
        public int $width = 512,
        public int $height = 512,
        public int $quality = 82,
        public Fit $fit = Fit::Crop,
        public bool $passthroughSvg = true,
    ) {}
}
