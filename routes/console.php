<?php

use App\Enums\VideoStatus;
use App\Models\MediaItem;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    $hours = max(1, (int) config('media-hls.abandon_after_hours'));

    MediaItem::query()
        ->where('status', VideoStatus::AwaitingUpload)
        ->where('created_at', '<', now()->subHours($hours))
        ->each(fn (MediaItem $item) => $item->delete());
})->hourly()->name('prune-abandoned-media-uploads')->withoutOverlapping();
