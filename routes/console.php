<?php

use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    $hours = max(1, (int) config('media-hls.abandon_after_hours'));

    Video::query()
        ->where('status', VideoStatus::AwaitingUpload)
        ->where('created_at', '<', now()->subHours($hours))
        ->each(fn (Video $video) => $video->delete());
})->hourly()->name('prune-abandoned-video-uploads')->withoutOverlapping();
