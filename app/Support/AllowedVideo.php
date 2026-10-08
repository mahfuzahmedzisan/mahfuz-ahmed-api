<?php

namespace App\Support;

final class AllowedVideo
{
    /** @var list<string> */
    public const EXTENSIONS = ['mp4', 'webm', 'mov', 'mkv', 'avi', 'm4v', 'mpeg', 'mpg'];

    /** @var list<string> */
    public const MIMES = [
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'video/x-matroska',
        'video/x-msvideo',
        'video/mpeg',
    ];

    public static function rejectionReason(string $filename, string $mime, int $size): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (! in_array($extension, self::EXTENSIONS, true)) {
            return 'This video format is not allowed.';
        }

        if (! in_array(strtolower($mime), self::MIMES, true)) {
            return 'This video type is not allowed.';
        }

        if ($size < 1) {
            return 'The video file is empty.';
        }

        $max = (int) config('media-hls.max_bytes');

        if ($size > $max) {
            return 'This video is larger than the upload limit.';
        }

        return null;
    }
}
