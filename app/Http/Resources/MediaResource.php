<?php

namespace App\Http\Resources;

use App\Models\MediaItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Playback of video and audio exposes the HLS master playlist only.
 * The Spatie source file is intentionally absent from this payload.
 *
 * @mixin MediaItem
 */
class MediaResource extends JsonResource
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
            'kind' => $this->kind->value,
            'alt' => $this->alt,
            'keywords' => $this->keywords ?? [],
            'status' => $this->status->value,
            'progress' => (int) $this->progress,
            'error_message' => $this->error_message,
            'mime' => $this->mime,
            'extension' => $this->extension,
            'size' => $this->size,
            'duration_seconds' => $this->duration_seconds,
            'width' => $this->width,
            'height' => $this->height,
            'url' => $this->fileUrl(),
            'stream_url' => $this->streamUrl(),
            'poster_url' => $this->posterUrl(),
            'created_at' => $this->created_at,
        ];
    }
}
