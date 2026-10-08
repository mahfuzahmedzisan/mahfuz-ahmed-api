<?php

namespace App\Enums;

enum MediaKind: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Pdf = 'pdf';
    case Document = 'document';

    public function streamsAsHls(): bool
    {
        return $this === self::Video || $this === self::Audio;
    }
}
