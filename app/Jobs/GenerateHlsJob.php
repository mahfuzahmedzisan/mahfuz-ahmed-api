<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Services\HlsTranscodeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

#[FailOnTimeout]
class GenerateHlsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $videoId)
    {
        $this->onQueue('media');
    }

    public function handle(HlsTranscodeService $service): void
    {
        $video = Video::query()->find($this->videoId);

        if ($video === null || $video->status === VideoStatus::Ready) {
            return;
        }

        $service->transcode($video);
    }

    public function failed(?Throwable $exception): void
    {
        $video = Video::query()->find($this->videoId);

        if ($video === null || $video->status === VideoStatus::Ready) {
            return;
        }

        $video->forceFill([
            'status' => VideoStatus::Failed,
            'error_message' => $exception?->getMessage() ?: 'Transcode failed.',
        ])->save();

        app(HlsTranscodeService::class)->broadcast($video);
    }
}
