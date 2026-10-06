<?php

namespace App\Support;

final class AllowedAvatar
{
    public const MAX_KILOBYTES = 5120;

    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        return [
            'required',
            'file',
            'max:'.self::MAX_KILOBYTES,
            'mimes:jpeg,jpg,png,gif,webp,bmp,svg',
            'mimetypes:image/jpeg,image/png,image/gif,image/webp,image/bmp,image/svg+xml,image/svg,text/svg',
        ];
    }
}
