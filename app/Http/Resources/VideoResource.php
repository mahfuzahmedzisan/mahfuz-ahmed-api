<?php

namespace App\Http\Resources;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Playback exposes the HLS master playlist only. The Spatie source file
 * is intentionally absent from this payload.
 *
 * @mixin Video
 */
class VideoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'progress' => (int) $this->progress,
            'error_message' => $this->error_message,
            'duration_seconds' => $this->duration_seconds,
            'width' => $this->width,
            'height' => $this->height,
            'stream_url' => $this->streamUrl(),
            'poster_url' => $this->posterUrl(),
            'created_at' => $this->created_at,
        ];
    }
}
