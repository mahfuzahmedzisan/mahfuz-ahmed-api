<?php

namespace App\Services;

use App\Contracts\EncodesHls;
use App\Enums\VideoStatus;
use App\Events\VideoProcessingUpdated;
use App\Models\Video;
use RuntimeException;

final class HlsTranscodeService
{
    public function __construct(private EncodesHls $encoder) {}

    public function transcode(Video $video): void
    {
        $media = $video->getFirstMedia('source');

        if ($media === null) {
            throw new RuntimeException('Source media is missing.');
        }

        $video->forceFill([
            'status' => VideoStatus::Processing,
            'progress' => 0,
            'error_message' => null,
        ])->save();
        $this->broadcast($video);

        $last = -1;
        $playlist = $video->hlsDirectory().'/master.m3u8';

        $result = $this->encoder->export(
            $media->disk,
            $media->getPathRelativeToRoot(),
            $playlist,
            function (int $percent) use ($video, &$last): void {
                $percent = max(0, min(99, $percent));

                if ($percent < $last + 5) {
                    return;
                }

                $last = $percent;
                $video->forceFill(['progress' => $percent])->save();
                $this->broadcast($video);
            },
        );

        $video->forceFill([
            'status' => VideoStatus::Ready,
            'progress' => 100,
            'hls_path' => $playlist,
            'duration_seconds' => $result->durationSeconds,
            'width' => $result->width,
            'height' => $result->height,
            'error_message' => null,
        ])->save();
        $this->broadcast($video);
    }

    public function broadcast(Video $video): void
    {
        $fresh = $video->fresh() ?? $video;

        broadcast(new VideoProcessingUpdated(
            userId: (int) $fresh->uploaded_by,
            videoId: $fresh->id,
            status: $fresh->status->value,
            progress: (int) $fresh->progress,
            streamUrl: $fresh->streamUrl(),
            errorMessage: $fresh->error_message,
        ));
    }
}
