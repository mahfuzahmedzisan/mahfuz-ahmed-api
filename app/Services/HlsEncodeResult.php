<?php

namespace App\Services;

final class HlsEncodeResult
{
    public function __construct(
        public ?int $durationSeconds,
        public ?int $width,
        public ?int $height,
    ) {}
}
