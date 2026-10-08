<?php

namespace App\Services;

use App\Contracts\EncodesHls;
use FFMpeg\Format\Video\X264;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
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
        string $kind = 'video',
    ): HlsEncodeResult {
        if ($kind === 'audio') {
            return $this->exportAudio($disk, $sourceRelativePath, $playlistRelativePath, $onProgress);
        }

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

        foreach ($this->rungs($width, $height) as $rung) {
            $format = (new X264)
                ->setKiloBitrate($rung['video'])
                ->setAudioKiloBitrate($rung['audio']);

            $export->addFormat($format, function (HLSVideoFilters $filters) use ($rung): void {
                $filters->addFilter(function ($complex, string $in, string $out) use ($rung): void {
                    $complex->custom($in, self::scaleFilter($rung['width'], $rung['height']), $out);
                });
            });
        }

        $export->save($playlistRelativePath);

        return new HlsEncodeResult($duration, $width, $height);
    }

    private function exportAudio(
        string $disk,
        string $sourceRelativePath,
        string $playlistRelativePath,
        ?callable $onProgress,
    ): HlsEncodeResult {
        $source = Storage::disk($disk)->path($sourceRelativePath);
        $playlistPath = Storage::disk($disk)->path($playlistRelativePath);
        $root = dirname($playlistPath);

        if (! is_dir($root) && ! mkdir($root, 0755, true) && ! is_dir($root)) {
            throw new \RuntimeException('Unable to prepare the audio stream directory.');
        }

        $ffmpeg = (string) config('laravel-ffmpeg.ffmpeg.binaries');
        $variants = [96, 160];
        $lines = ['#EXTM3U', '#EXT-X-VERSION:3'];

        foreach ($variants as $index => $kbps) {
            $folder = $root.DIRECTORY_SEPARATOR.$kbps.'k';

            if (! is_dir($folder) && ! mkdir($folder, 0755, true) && ! is_dir($folder)) {
                throw new \RuntimeException('Unable to prepare an audio rendition.');
            }

            $playlist = $folder.DIRECTORY_SEPARATOR.'index.m3u8';
            $result = Process::timeout(1800)->run([
                $ffmpeg,
                '-y',
                '-i',
                $source,
                '-vn',
                '-c:a',
                'aac',
                '-b:a',
                $kbps.'k',
                '-ac',
                '2',
                '-hls_time',
                '10',
                '-hls_playlist_type',
                'vod',
                '-hls_segment_filename',
                $folder.DIRECTORY_SEPARATOR.'seg-%03d.aac',
                $playlist,
            ]);

            if (! $result->successful() || ! is_file($playlist)) {
                throw new \RuntimeException('Audio encoding failed.');
            }

            $lines[] = '#EXT-X-STREAM-INF:BANDWIDTH='.($kbps * 1000).',CODECS="mp4a.40.2"';
            $lines[] = $kbps.'k/index.m3u8';

            if ($onProgress !== null) {
                $onProgress((int) round((($index + 1) / count($variants)) * 99));
            }
        }

        Storage::disk($disk)->put($playlistRelativePath, implode("\n", $lines)."\n");

        return new HlsEncodeResult(null, null, null);
    }

    /**
     * Fits inside the rung and keeps the source shape. A tall video stays tall.
     */
    public static function scaleFilter(int $maxWidth, int $maxHeight): string
    {
        return "scale=w={$maxWidth}:h={$maxHeight}:force_original_aspect_ratio=decrease,scale=trunc(iw/2)*2:trunc(ih/2)*2,setsar=1";
    }

    /**
     * A rung is skipped when both of its sides are larger than the source.
     * At least one rung always remains.
     *
     * @return list<array{width: int, height: int, video: int, audio: int}>
     */
    private function rungs(?int $sourceWidth, ?int $sourceHeight): array
    {
        $ladder = [
            ['width' => 640, 'height' => 360, 'video' => 800, 'audio' => 96],
            ['width' => 1280, 'height' => 720, 'video' => 2500, 'audio' => 128],
            ['width' => 1920, 'height' => 1080, 'video' => 5000, 'audio' => 192],
        ];

        if ($sourceWidth === null || $sourceHeight === null) {
            return $ladder;
        }

        $fitting = array_values(array_filter(
            $ladder,
            function (array $rung) use ($sourceWidth, $sourceHeight): bool {
                $asLandscape = $sourceWidth >= $rung['width'] && $sourceHeight >= $rung['height'];
                $asPortrait = $sourceHeight >= $rung['width'] && $sourceWidth >= $rung['height'];

                return $asLandscape || $asPortrait;
            },
        ));

        return $fitting === [] ? [$ladder[0]] : $fitting;
    }
}
