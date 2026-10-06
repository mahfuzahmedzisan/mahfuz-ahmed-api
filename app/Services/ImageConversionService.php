<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Image\Image;
use Spatie\LaravelImageOptimizer\Facades\ImageOptimizer;
use Throwable;

class ImageConversionService
{
    /**
     * Stores an SVG unchanged, or encodes any other raster image as WebP.
     * Throws when WebP cannot be produced. Never falls back to PNG.
     */
    public function convertAndStore(
        string $disk,
        string $directory,
        UploadedFile|string $source,
        string $basename,
        ?ImageConversionOptions $options = null,
    ): StoredImageResult {
        $options ??= new ImageConversionOptions;
        $format = $this->detectFormat($source);
        $storage = Storage::disk($disk);
        $directory = trim(str_replace('\\', '/', $directory), '/');
        $storage->makeDirectory($directory);

        if ($format === 'svg' && $options->passthroughSvg) {
            $relative = $directory.'/'.$basename.'.svg';
            $absolute = $storage->path($relative);
            $this->writeBytes($absolute, $this->readContents($source));
            $this->optimizeQuietly($absolute);

            return new StoredImageResult($relative, 'svg', $disk, 'image/svg+xml');
        }

        if (! $this->canEncodeWebp()) {
            throw new RuntimeException('WebP encoding is unavailable. Enable GD WebP or install cwebp.');
        }

        [$readable, $cleanup] = $this->prepareReadableSource($source);
        $relative = $directory.'/'.$basename.'.webp';
        $absolute = $storage->path($relative);

        try {
            $this->encodeWebp($readable, $absolute, $options);
        } catch (Throwable $exception) {
            if (is_file($absolute)) {
                @unlink($absolute);
            }

            throw $exception;
        } finally {
            $this->cleanup($cleanup);
        }

        return new StoredImageResult($relative, 'webp', $disk, 'image/webp');
    }

    public function deleteStoredPath(string $disk, ?string $relativePath, ?callable $guard = null): void
    {
        if (! is_string($relativePath) || $relativePath === '') {
            return;
        }

        $normalized = str_replace('\\', '/', $relativePath);

        if (str_contains($normalized, '..') || str_starts_with($normalized, '/')) {
            return;
        }

        if ($guard !== null && ! $guard($normalized)) {
            return;
        }

        Storage::disk($disk)->delete($normalized);
    }

    public function detectFormat(UploadedFile|string $source): string
    {
        $mime = $this->mime($source);
        $path = $this->pathOf($source);

        if (str_contains($mime, 'svg') || $this->isSvgFile($path)) {
            return 'svg';
        }

        if (str_contains($mime, 'webp') || $this->isWebpFile($path)) {
            return 'webp';
        }

        return match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpeg',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'gif') => 'gif',
            str_contains($mime, 'bmp') => 'bmp',
            str_contains($mime, 'avif') => 'avif',
            default => 'unknown',
        };
    }

    public function canEncodeWebp(): bool
    {
        return function_exists('imagewebp') || $this->resolveBinary('cwebp') !== null;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    public function prepareReadableSource(UploadedFile|string $source): array
    {
        $path = $this->pathOf($source);
        $format = $this->detectFormat($source);

        if ($format !== 'webp' || function_exists('imagecreatefromwebp')) {
            return [$path, []];
        }

        $dwebp = $this->resolveBinary('dwebp');
        if ($dwebp === null) {
            throw new RuntimeException('This server cannot read WebP images. Install the webp package or enable GD WebP.');
        }

        $dest = tempnam(sys_get_temp_dir(), 'image').'.png';
        $result = Process::timeout(30)->run([$dwebp, $path, '-o', $dest]);

        if ($result->failed() || ! is_file($dest)) {
            if (is_file($dest)) {
                @unlink($dest);
            }

            throw new RuntimeException(trim($result->errorOutput()) ?: 'dwebp failed.');
        }

        return [$dest, [$dest]];
    }

    private function encodeWebp(string $readable, string $absolute, ImageConversionOptions $options): void
    {
        if (function_exists('imagewebp')) {
            Image::load($readable)
                ->fit($options->fit, $options->width, $options->height)
                ->quality($options->quality)
                ->format('webp')
                ->optimize()
                ->save($absolute);

            return;
        }

        $staged = tempnam(sys_get_temp_dir(), 'image').'.png';

        try {
            Image::load($readable)
                ->fit($options->fit, $options->width, $options->height)
                ->format('png')
                ->save($staged);

            $cwebp = $this->resolveBinary('cwebp');
            if ($cwebp === null) {
                throw new RuntimeException('cwebp is not available.');
            }

            $result = Process::timeout(30)->run([
                $cwebp,
                '-q',
                (string) $options->quality,
                $staged,
                '-o',
                $absolute,
            ]);

            if ($result->failed()) {
                throw new RuntimeException(trim($result->errorOutput()) ?: 'cwebp failed.');
            }
        } finally {
            if (is_file($staged)) {
                @unlink($staged);
            }
        }
    }

    private function mime(UploadedFile|string $source): string
    {
        if ($source instanceof UploadedFile) {
            return (string) $source->getMimeType();
        }

        $detected = mime_content_type($source);

        return is_string($detected) ? $detected : '';
    }

    private function pathOf(UploadedFile|string $source): string
    {
        return $source instanceof UploadedFile ? $source->getRealPath() : $source;
    }

    private function readContents(UploadedFile|string $source): string
    {
        $contents = file_get_contents($this->pathOf($source));

        if ($contents === false) {
            throw new RuntimeException('Unable to read the uploaded image.');
        }

        return $contents;
    }

    private function writeBytes(string $absolute, string $contents): void
    {
        $directory = dirname($absolute);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the image directory.');
        }

        if (file_put_contents($absolute, $contents) === false) {
            throw new RuntimeException('Unable to store the image.');
        }
    }

    private function optimizeQuietly(string $absolute): void
    {
        try {
            ImageOptimizer::optimize($absolute);
        } catch (Throwable) {
            // Missing optimizer binaries must not fail an otherwise valid store.
        }
    }

    private function isSvgFile(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 256);
        fclose($handle);

        if (! is_string($header)) {
            return false;
        }

        $trimmed = ltrim($header);

        return str_starts_with($trimmed, '<svg')
            || (str_starts_with($trimmed, '<?xml') && str_contains($header, '<svg'));
    }

    private function isWebpFile(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 12);
        fclose($handle);

        return is_string($header) && str_starts_with($header, 'RIFF') && str_contains($header, 'WEBP');
    }

    private function resolveBinary(string $name): ?string
    {
        if (! preg_match('/^[a-z0-9]+$/', $name)) {
            return null;
        }

        $result = PHP_OS_FAMILY === 'Windows'
            ? Process::run(['where', $name])
            : Process::run('command -v '.$name);

        if ($result->failed()) {
            return null;
        }

        $line = trim(strtok($result->output(), "\r\n") ?: '');

        return $line !== '' ? $line : null;
    }

    /**
     * @param  list<string>  $paths
     */
    private function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
