<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

class AvatarStorageService
{
    public const SIZE = 512;

    public const QUALITY = 82;

    public function __construct(private readonly ImageConversionService $images) {}

    /**
     * Writes the new avatar first, persists the path, then deletes the previous
     * file. A failed encode never removes the avatar the user already has.
     */
    public function store(User $user, UploadedFile $file): User
    {
        $previous = $user->avatar;

        $stored = $this->images->convertAndStore(
            disk: 'public',
            directory: 'avatars/'.$user->getKey(),
            source: $file,
            basename: (string) Str::ulid(),
            options: new ImageConversionOptions(
                width: self::SIZE,
                height: self::SIZE,
                quality: self::QUALITY,
            ),
        );

        try {
            $user->forceFill(['avatar' => $stored->relativePath])->save();
        } catch (Throwable $exception) {
            $this->images->deleteStoredPath('public', $stored->relativePath);

            throw $exception;
        }

        if (is_string($previous) && $previous !== $stored->relativePath) {
            $this->deletePath($previous);
        }

        return $user->fresh();
    }

    public function delete(User $user): User
    {
        $this->deletePath($user->avatar);
        $user->forceFill(['avatar' => null])->save();

        return $user->fresh();
    }

    /**
     * Removes the stored file without touching the user row. Used when the
     * account itself is about to be deleted.
     */
    public function purge(User $user): void
    {
        $this->deletePath($user->avatar);
    }

    public function deletePath(?string $path): void
    {
        $this->images->deleteStoredPath('public', $path, fn (string $candidate): bool => $this->isAvatarPath($candidate));
    }

    public function isAvatarPath(?string $path): bool
    {
        if (! is_string($path) || $path === '') {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);

        if (str_contains($normalized, '..') || str_starts_with($normalized, '/')) {
            return false;
        }

        return str_starts_with($normalized, 'avatars/');
    }
}
