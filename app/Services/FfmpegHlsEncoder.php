<?php

namespace App\Services;

use App\Contracts\EncodesHls;
use FFMpeg\Format\Video\X264;
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSVideoFilters;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use Throwable;

final class FfmpegHlsEncoder implements EncodesHls
{
    public function export(
        string $disk,
        string $sourceRelativePath,
        string $playlistRelativePath,
        ?callable $onProgress = null,
    ): HlsEncodeResult {
        $width = null;
        $height = null;
        $duration = null;

        try {
            // Probe on its own opener. Reading the stream opens the file as a
            // normal video. exportForHLS needs a fresh opener so it can open
            // the same file as advanced media; reusing this one makes save()
            // pass VideoMedia into AdvancedOutputMapping.
            $probe = FFMpeg::fromDisk($disk)->open($sourceRelativePath);
            $stream = $probe->getVideoStream();
            if ($stream !== null) {
                $dimensions = $stream->getDimensions();
                $width = $dimensions->getWidth();
                $height = $dimensions->getHeight();
            }

            $duration = (int) round($probe->getDurationInSeconds());
        } catch (Throwable) {
            $width = null;
            $height = null;
            $duration = null;
        }

        $export = FFMpeg::fromDisk($disk)->open($sourceRelativePath)->exportForHLS()->setSegmentLength(10);

        if ($onProgress !== null) {
            $export->onProgress(function ($percentage) use ($onProgress): void {
                $onProgress((int) $percentage);
            });
        }

        foreach ($this->rungs($height) as $rung) {
            $format = (new X264)
                ->setKiloBitrate($rung['video'])
                ->setAudioKiloBitrate($rung['audio']);

            $export->addFormat($format, function (HLSVideoFilters $filters) use ($rung): void {
                $filters->resize($rung['width'], $rung['height']);
            });
        }

        $export->save($playlistRelativePath);

        return new HlsEncodeResult($duration, $width, $height);
    }

    /**
     * 1080p is omitted when the source is shorter. At least one rung always remains.
     *
     * @return list<array{width: int, height: int, video: int, audio: int}>
     */
    private function rungs(?int $sourceHeight): array
    {
        $ladder = [
            ['width' => 640, 'height' => 360, 'video' => 800, 'audio' => 96],
            ['width' => 1280, 'height' => 720, 'video' => 2500, 'audio' => 128],
            ['width' => 1920, 'height' => 1080, 'video' => 5000, 'audio' => 192],
        ];

        if ($sourceHeight === null) {
            return $ladder;
        }

        $fitting = array_values(array_filter(
            $ladder,
            fn (array $rung): bool => $rung['height'] <= $sourceHeight,
        ));

        return $fitting === [] ? [$ladder[0]] : $fitting;
    }
}
