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

        foreach (self::rungsFor($width, $height) as $rung) {
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
            $result = Process::timeout(7200)->run([
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
     * The top rung is the source itself. Lower rungs follow its shorter side,
     * never larger than the source, and keep the source orientation.
     *
     * 720 → 360 + original. 1080 → 720 + original. 2K → 720 + 1080 + original.
     * 4K → 1080 + 2K + original. Below 720, only the original.
     *
     * @return list<array{width: int, height: int, video: int, audio: int}>
     */
    public static function rungsFor(?int $sourceWidth, ?int $sourceHeight): array
    {
        if ($sourceWidth === null || $sourceHeight === null || $sourceWidth < 2 || $sourceHeight < 2) {
            return [
                self::standardRung(720, false),
                self::standardRung(1080, false),
            ];
        }

        $portrait = $sourceHeight > $sourceWidth;
        $short = min($sourceWidth, $sourceHeight);
        $below = match (true) {
            $short >= 2160 => [1080, 1440],
            $short >= 1440 => [720, 1080],
            $short >= 1080 => [720],
            $short >= 720 => [360],
            default => [],
        };

        $rungs = [];

        foreach ($below as $tier) {
            $rung = self::standardRung($tier, $portrait);

            if ($rung['width'] > $sourceWidth || $rung['height'] > $sourceHeight) {
                continue;
            }

            if ($rung['width'] === $sourceWidth && $rung['height'] === $sourceHeight) {
                continue;
            }

            $rungs[] = $rung;
        }

        $rate = self::rateForShortSide($short);
        $rungs[] = [
            'width' => $sourceWidth,
            'height' => $sourceHeight,
            'video' => $rate['video'],
            'audio' => $rate['audio'],
        ];

        return $rungs;
    }

    /**
     * @return array{width: int, height: int, video: int, audio: int}
     */
    private static function standardRung(int $tier, bool $portrait): array
    {
        $long = match ($tier) {
            360 => 640,
            720 => 1280,
            1080 => 1920,
            1440 => 2560,
            default => 3840,
        };

        $rate = self::rateForShortSide($tier);

        return [
            'width' => $portrait ? $tier : $long,
            'height' => $portrait ? $long : $tier,
            'video' => $rate['video'],
            'audio' => $rate['audio'],
        ];
    }

    /**
     * @return array{video: int, audio: int}
     */
    private static function rateForShortSide(int $short): array
    {
        return match (true) {
            $short >= 2160 => ['video' => 16000, 'audio' => 192],
            $short >= 1440 => ['video' => 8000, 'audio' => 192],
            $short >= 1080 => ['video' => 5000, 'audio' => 192],
            $short >= 720 => ['video' => 2500, 'audio' => 128],
            default => ['video' => 800, 'audio' => 96],
        };
    }
}
