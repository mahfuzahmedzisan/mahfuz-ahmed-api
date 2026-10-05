<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Throwable;

class AvatarStorageService
{
    public const SIZE = 512;

    public const QUALITY = 82;

    /**
     * Writes a new WebP first, persists the path, then deletes the previous
     * file. A failed encode or save never removes the avatar the user already has.
     */
    public function store(User $user, UploadedFile $file): User
    {
        $previous = $user->avatar;
        $relative = 'avatars/'.$user->getKey().'/'.Str::ulid().'.webp';
        $disk = Storage::disk('public');
        $disk->makeDirectory('avatars/'.$user->getKey());

        $absolute = $disk->path($relative);

        try {
            Image::load($file->getRealPath())
                ->fit(Fit::Crop, self::SIZE, self::SIZE)
                ->quality(self::QUALITY)
                ->format('webp')
                ->optimize()
                ->save($absolute);
        } catch (Throwable $exception) {
            $this->deletePath($relative);
            throw $exception;
        }

        try {
            $user->forceFill(['avatar' => $relative])->save();
        } catch (Throwable $exception) {
            $this->deletePath($relative);
            throw $exception;
        }

        if (is_string($previous) && $previous !== $relative) {
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
        if (! $this->isAvatarPath($path)) {
            return;
        }

        Storage::disk('public')->delete($path);
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
