<?php

namespace App\Support;

use App\Enums\MediaKind;
use Illuminate\Support\Facades\Storage;

final class MediaUrl
{
    /**
     * @param  string|list<string>|null  $value
     * @return string|list<string>|null
     */
    public static function format(string|array|null $value): string|array|null
    {
        if (is_array($value)) {
            $formatted = [];

            foreach ($value as $item) {
                if (! is_string($item)) {
                    continue;
                }

                $one = self::one($item);

                if ($one !== null) {
                    $formatted[] = $one;
                }
            }

            return $formatted;
        }

        if (! is_string($value)) {
            return null;
        }

        return self::one($value);
    }

    /**
     * Canonical value for the database: a public-disk path, or an external http(s) URL.
     */
    public static function store(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $formatted = self::one($value);

        if ($formatted === null) {
            return null;
        }

        if (preg_match('#^https?://#i', $formatted) === 1) {
            $path = parse_url($formatted, PHP_URL_PATH);

            if (is_string($path) && preg_match('#/storage/(.+)$#', $path, $matches) === 1) {
                return self::relativePath(rawurldecode($matches[1]));
            }

            return $formatted;
        }

        return self::relativePath($value);
    }

    /**
     * @param  list<string>  $kinds  Empty allows every known media extension.
     */
    public static function matchesKinds(string $value, array $kinds): bool
    {
        $extension = self::extension($value);

        if ($extension === '') {
            return false;
        }

        if ($kinds === []) {
            return isset(AllowedMedia::TYPES[$extension]) || $extension === 'ico';
        }

        if ($extension === 'ico' && in_array('image', $kinds, true)) {
            return true;
        }

        $kind = AllowedMedia::kindFor('file.'.$extension);

        return $kind instanceof MediaKind && in_array($kind->value, $kinds, true);
    }

    public static function extension(string $value): string
    {
        $path = parse_url($value, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $value;

        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    private static function one(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || preg_match('#^(javascript|data|file|vbscript|blob):#i', $value) === 1) {
            return null;
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            $parts = parse_url($value);

            if (! is_array($parts) || ! isset($parts['host']) || $parts['host'] === '') {
                return null;
            }

            if (isset($parts['user']) || isset($parts['pass'])) {
                return null;
            }

            $scheme = strtolower((string) ($parts['scheme'] ?? ''));

            if (! in_array($scheme, ['http', 'https'], true)) {
                return null;
            }

            return $value;
        }

        $relative = self::relativePath($value);

        if ($relative === null) {
            return null;
        }

        return Storage::disk('public')->url($relative);
    }

    private static function relativePath(string $value): ?string
    {
        $path = trim($value);

        if (str_starts_with($path, '/api/storage/')) {
            $path = substr($path, strlen('/api/storage/'));
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');

        if ($path === '' || str_contains($path, '..') || str_contains($path, '://') || str_contains($path, "\0")) {
            return null;
        }

        return $path;
    }
}
