<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VideoProcessingUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $userId,
        public int $videoId,
        public string $status,
        public int $progress,
        public ?string $streamUrl,
        public ?string $errorMessage,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'video.processing';
    }

    /**
     * @return array{video_id: int, status: string, progress: int, stream_url: ?string, error_message: ?string}
     */
    public function broadcastWith(): array
    {
        return [
            'video_id' => $this->videoId,
            'status' => $this->status,
            'progress' => $this->progress,
            'stream_url' => $this->streamUrl,
            'error_message' => $this->errorMessage,
        ];
    }
}
