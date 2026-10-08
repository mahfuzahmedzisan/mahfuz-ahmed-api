<?php

namespace App\Models;

use App\Enums\VideoStatus;
use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property VideoStatus $status
 */
#[Fillable([
    'ulid',
    'title',
    'slug',
    'status',
    'progress',
    'error_message',
    'duration_seconds',
    'width',
    'height',
    'hls_path',
    'tus_id',
    'uploaded_by',
])]
class Video extends Model implements HasMedia
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory, InteractsWithMedia;

    protected $attributes = [
        'status' => 'awaiting_upload',
        'progress' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (Video $video): void {
            if (! $video->ulid) {
                $video->ulid = (string) Str::ulid();
            }
        });

        static::deleting(function (Video $video): void {
            if ($video->ulid) {
                Storage::disk('public')->deleteDirectory('videos/'.$video->ulid);
            }

            if ($video->tus_id) {
                $directory = rtrim((string) config('media-hls.upload_dir'), DIRECTORY_SEPARATOR);
                @unlink($directory.DIRECTORY_SEPARATOR.$video->tus_id);
                @unlink($directory.DIRECTORY_SEPARATOR.$video->tus_id.'.info');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VideoStatus::class,
            'progress' => 'integer',
            'duration_seconds' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('source')
            ->singleFile()
            ->useDisk('public');

        $this->addMediaCollection('poster')
            ->singleFile()
            ->useDisk('public');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'video';
        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    public function hlsDirectory(): string
    {
        return 'videos/'.$this->ulid.'/hls';
    }

    public function streamUrl(): ?string
    {
        if ($this->status !== VideoStatus::Ready || ! $this->hls_path) {
            return null;
        }

        return Storage::disk('public')->url($this->hls_path);
    }

    public function posterUrl(): ?string
    {
        $url = $this->getFirstMediaUrl('poster');

        return $url !== '' ? $url : null;
    }
}
