<?php

use App\Services\FfmpegHlsEncoder;

it('scales a tall video inside the ladder instead of forcing 16:9', function () {
    expect(FfmpegHlsEncoder::scaleFilter(1280, 720))
        ->toContain('force_original_aspect_ratio=decrease')
        ->not->toContain('scale=1280:720');
});
