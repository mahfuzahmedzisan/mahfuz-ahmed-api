<?php

namespace Database\Factories;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    protected $model = Video::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence(3);

        return [
            'ulid' => (string) Str::ulid(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'status' => VideoStatus::AwaitingUpload,
            'progress' => 0,
            'uploaded_by' => User::factory(),
        ];
    }

    public function uploaded(): static
    {
        return $this->state(fn () => [
            'status' => VideoStatus::Uploaded,
        ]);
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VideoStatus::Ready,
            'progress' => 100,
            'hls_path' => 'videos/'.$attributes['ulid'].'/hls/master.m3u8',
            'duration_seconds' => 12,
            'width' => 1280,
            'height' => 720,
        ]);
    }
}
