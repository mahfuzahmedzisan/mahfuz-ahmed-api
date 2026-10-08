<?php

namespace App\Contracts;

use App\Services\HlsEncodeResult;

interface EncodesHls
{
    /**
     * @param  (callable(int): void)|null  $onProgress
     */
    public function export(
        string $disk,
        string $sourceRelativePath,
        string $playlistRelativePath,
        ?callable $onProgress = null,
    ): HlsEncodeResult;
}
