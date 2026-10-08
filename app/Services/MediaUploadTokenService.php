<?php

namespace App\Services;

use App\Models\MediaItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class MediaUploadTokenService
{
    /**
     * @return array{media_id: int, user_id: int}|null
     */
    public function find(string $token): ?array
    {
        $payload = Cache::get($this->key($token));

        if (! is_array($payload) || ! isset($payload['user_id'])) {
            return null;
        }

        $mediaId = (int) ($payload['media_id'] ?? $payload['video_id'] ?? 0);

        if ($mediaId < 1) {
            return null;
        }

        return [
            'media_id' => $mediaId,
            'user_id' => (int) $payload['user_id'],
        ];
    }

    public function issue(MediaItem $item): string
    {
        $token = Str::random(64);

        Cache::put($this->key($token), [
            'media_id' => $item->id,
            'user_id' => (int) $item->uploaded_by,
        ], now()->addSeconds((int) config('media-hls.token_ttl_seconds')));

        return $token;
    }

    public function forget(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return 'media-upload:'.hash('sha256', $token);
    }
}
