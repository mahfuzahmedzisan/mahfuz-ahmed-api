<?php

namespace App\Support;

use App\Services\ClamAvScanner;

final class UploadInspector
{
    public function __construct(private ClamAvScanner $clam) {}

    public function rejectionReason(string $path, string $filename): ?string
    {
        if (! is_file($path)) {
            return 'Finished upload file is missing.';
        }

        $extension = AllowedMedia::extension($filename);
        $detected = $this->detectedMime($path);

        if (! $this->magicMatches($path, $extension)) {
            return 'The file contents do not match its type.';
        }

        $expected = AllowedMedia::TYPES[$extension]['mimes'] ?? [];

        if ($detected !== null && $detected !== 'application/octet-stream' && $expected !== [] && ! in_array($detected, $expected, true)) {
            if (! $this->textMimeIsLoose($extension, $detected)) {
                return 'The file contents do not match its type.';
            }
        }

        $header = (string) file_get_contents($path, false, null, 0, 512);

        if ($this->looksDangerous($header, $extension)) {
            return 'This file was rejected because it looks like executable or script content.';
        }

        return $this->clam->rejectionReason($path);
    }

    private function detectedMime(string $path): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return is_string($mime) ? strtolower($mime) : null;
    }

    private function textMimeIsLoose(string $extension, string $detected): bool
    {
        return in_array($extension, ['txt', 'csv'], true)
            && in_array($detected, ['text/plain', 'text/csv', 'application/csv', 'text/x-c', 'inode/x-empty'], true);
    }

    private function magicMatches(string $path, string $extension): bool
    {
        $bytes = (string) file_get_contents($path, false, null, 0, 64);

        return match ($extension) {
            'jpg', 'jpeg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
            'png' => str_starts_with($bytes, "\x89PNG"),
            'gif' => str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a'),
            'webp' => str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 8, 4), 'WEBP'),
            'pdf' => str_starts_with($bytes, '%PDF'),
            'mp4', 'mov', 'm4a' => strlen($bytes) >= 12 && substr($bytes, 4, 4) === 'ftyp',
            'webm', 'mkv' => str_starts_with($bytes, "\x1A\x45\xDF\xA3"),
            'mp3' => str_starts_with($bytes, 'ID3') || (strlen($bytes) >= 2 && ord($bytes[0]) === 0xFF && (ord($bytes[1]) & 0xE0) === 0xE0),
            'wav' => str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 8, 4), 'WAVE'),
            'ogg' => str_starts_with($bytes, 'OggS'),
            'doc', 'xls', 'ppt' => str_starts_with($bytes, "\xD0\xCF\x11\xE0"),
            'docx', 'xlsx', 'pptx' => str_starts_with($bytes, "PK\x03\x04"),
            'txt', 'csv' => ! str_contains(substr($bytes, 0, 32), "\0"),
            'svg' => $this->looksLikeSvg($bytes),
            default => false,
        };
    }

    private function looksLikeSvg(string $bytes): bool
    {
        $start = ltrim($bytes, "\xEF\xBB\xBF\t\r\n ");
        $head = strtolower(substr($start, 0, 120));

        return str_starts_with($head, '<svg')
            || str_starts_with($head, '<?xml')
            || str_starts_with($head, '<!doctype svg');
    }

    private function looksDangerous(string $header, string $extension): bool
    {
        $start = ltrim($header);

        if (str_starts_with($header, 'MZ') || str_starts_with($header, "\x7FELF") || str_starts_with($start, '#!')) {
            return true;
        }

        $sample = strtolower(substr($header, 0, 256));

        if (str_contains($sample, '<?php') || str_contains($sample, '<?=')) {
            return true;
        }

        return $extension !== 'svg' && str_contains($sample, '<script');
    }
}
