<?php

namespace App\Support;

class FrontendOrigins
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $urls = config('services.frontend.urls', []);

        if (! is_array($urls)) {
            return [];
        }

        $normalized = [];

        foreach ($urls as $url) {
            if (! is_string($url)) {
                continue;
            }

            $origin = self::normalize($url);

            if ($origin !== null) {
                $normalized[] = $origin;
            }
        }

        return array_values(array_unique($normalized));
    }

    public static function contains(string $origin): bool
    {
        $normalized = self::normalize($origin);

        if ($normalized === null) {
            return false;
        }

        return in_array($normalized, self::all(), true);
    }

    public static function default(): string
    {
        return self::all()[0] ?? '';
    }

    /**
     * Scheme + host + port only. Paths, queries, and trailing slashes are dropped
     * so `https://app.example.com/admin` and `https://app.example.com` match.
     */
    public static function normalize(string $origin): ?string
    {
        $origin = trim($origin);

        if ($origin === '') {
            return null;
        }

        $parts = parse_url($origin);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }
}
