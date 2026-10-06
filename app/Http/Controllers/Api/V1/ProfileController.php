<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AvatarStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use RuntimeException;
use Throwable;

class ProfileController extends Controller
{
    public function update(Request $request, UpdatesUserProfileInformation $updater): JsonResponse
    {
        $updater->update($request->user(), $request->all());

        return $this->apiSuccess('Profile updated successfully.', [
            'user' => new UserResource($request->user()->fresh()),
        ]);
    }

    public function updatePassword(Request $request, UpdatesUserPasswords $updater): JsonResponse
    {
        $updater->update($request->user(), $request->all());

        return $this->apiSuccess('Password updated successfully.');
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_notifications' => ['sometimes', 'boolean'],
            'push_notifications' => ['sometimes', 'boolean'],
            'theme' => ['sometimes', 'string', Rule::in(['light', 'dark', 'system'])],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->fill($validated)->save();

        return $this->apiSuccess('Preferences updated successfully.', [
            'user' => new UserResource($user->fresh()),
        ]);
    }

    public function updateAvatar(Request $request, AvatarStorageService $avatars): JsonResponse
    {
        $request->validate([
            'avatar' => [
                'required',
                'file',
                'max:5120',
                'mimetypes:image/jpeg,image/png,image/gif,image/webp,image/bmp,image/svg+xml',
            ],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $user = $avatars->store($user, $request->file('avatar'));
        } catch (Throwable $exception) {
            report($exception);

            return $this->apiUnprocessable(
                $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Unable to process avatar.',
            );
        }

        return $this->apiSuccess('Avatar updated successfully.', [
            'user' => new UserResource($user),
        ]);
    }

    public function destroyAvatar(Request $request, AvatarStorageService $avatars): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user = $avatars->delete($user);

        return $this->apiSuccess('Avatar removed successfully.', [
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Deletes the authenticated user's account. Revokes every Passport token
     * first so no token outlives the account it was issued for.
     */
    public function destroy(Request $request, AvatarStorageService $avatars): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:api'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $avatars->purge($user);

        $user->tokens()->update(['revoked' => true]);
        $user->delete();

        return $this->apiSuccess('Account deleted successfully.');
    }
}
