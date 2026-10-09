<?php

use App\Services\FfmpegHlsEncoder;

it('scales a tall video inside the ladder instead of forcing 16:9', function () {
    expect(FfmpegHlsEncoder::scaleFilter(1280, 720))
        ->toContain('force_original_aspect_ratio=decrease')
        ->not->toContain('scale=1280:720');
});

it('keeps a 720 source and adds only 360 below it', function () {
    expect(FfmpegHlsEncoder::rungsFor(1280, 720))->toBe([
        ['width' => 640, 'height' => 360, 'video' => 800, 'audio' => 96],
        ['width' => 1280, 'height' => 720, 'video' => 2500, 'audio' => 128],
    ]);
});

it('keeps a 1080 source and skips 360', function () {
    expect(FfmpegHlsEncoder::rungsFor(1920, 1080))->toBe([
        ['width' => 1280, 'height' => 720, 'video' => 2500, 'audio' => 128],
        ['width' => 1920, 'height' => 1080, 'video' => 5000, 'audio' => 192],
    ]);
});

it('keeps a 2K source with 720 and 1080 below it', function () {
    expect(FfmpegHlsEncoder::rungsFor(2560, 1440))->toBe([
        ['width' => 1280, 'height' => 720, 'video' => 2500, 'audio' => 128],
        ['width' => 1920, 'height' => 1080, 'video' => 5000, 'audio' => 192],
        ['width' => 2560, 'height' => 1440, 'video' => 8000, 'audio' => 192],
    ]);
});

it('keeps a 4K source with 1080 and 2K below it', function () {
    expect(FfmpegHlsEncoder::rungsFor(3840, 2160))->toBe([
        ['width' => 1920, 'height' => 1080, 'video' => 5000, 'audio' => 192],
        ['width' => 2560, 'height' => 1440, 'video' => 8000, 'audio' => 192],
        ['width' => 3840, 'height' => 2160, 'video' => 16000, 'audio' => 192],
    ]);
});

it('orients a portrait 1080 source so the lower rung stays tall', function () {
    expect(FfmpegHlsEncoder::rungsFor(1080, 1920))->toBe([
        ['width' => 720, 'height' => 1280, 'video' => 2500, 'audio' => 128],
        ['width' => 1080, 'height' => 1920, 'video' => 5000, 'audio' => 192],
    ]);
});
