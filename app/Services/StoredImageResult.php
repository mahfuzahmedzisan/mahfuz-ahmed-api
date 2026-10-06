<?php

namespace App\Services;

final readonly class StoredImageResult
{
    public function __construct(
        public string $relativePath,
        public string $extension,
        public string $disk,
        public string $mime,
    ) {}
}
