<?php

namespace App\Services;

use App\Models\Video;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class VideoUploadTokenService
{
    /**
     * @return array{video_id: int, user_id: int}|null
     */
    public function find(string $token): ?array
    {
        $payload = Cache::get($this->key($token));

        if (! is_array($payload) || ! isset($payload['video_id'], $payload['user_id'])) {
            return null;
        }

        return [
            'video_id' => (int) $payload['video_id'],
            'user_id' => (int) $payload['user_id'],
        ];
    }

    public function issue(Video $video): string
    {
        $token = Str::random(64);

        Cache::put($this->key($token), [
            'video_id' => $video->id,
            'user_id' => (int) $video->uploaded_by,
        ], now()->addSeconds((int) config('media-hls.token_ttl_seconds')));

        return $token;
    }

    public function forget(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return 'video-upload:'.hash('sha256', $token);
    }
}
