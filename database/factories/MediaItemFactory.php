<?php

namespace Database\Factories;

use App\Enums\MediaKind;
use App\Enums\VideoStatus;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaItem>
 */
class MediaItemFactory extends Factory
{
    protected $model = MediaItem::class;

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
            'kind' => MediaKind::Video,
            'keywords' => [],
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
            'hls_path' => 'media/'.$attributes['ulid'].'/hls/master.m3u8',
            'duration_seconds' => 12,
            'width' => 1280,
            'height' => 720,
        ]);
    }

    public function audio(): static
    {
        return $this->state(fn () => [
            'kind' => MediaKind::Audio,
        ]);
    }
}
