<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class ProfileController extends Controller
{
    public function update(Request $request, UpdatesUserProfileInformation $updater): JsonResponse
    {
        $updater->update($request->user(), $request->all());

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => [
                'user' => new UserResource($request->user()->fresh()),
            ],
        ]);
    }

    public function updatePassword(Request $request, UpdatesUserPasswords $updater): JsonResponse
    {
        $updater->update($request->user(), $request->all());

        return response()->json([
            'message' => 'Password updated successfully.',
            'data' => null,
        ]);
    }

    /**
     * Deletes the authenticated user's account. Revokes every Passport token
     * first so no token outlives the account it was issued for.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:api'],
        ]);

        $user = $request->user();
        $user->tokens()->update(['revoked' => true]);
        $user->delete();

        return response()->json([
            'message' => 'Account deleted successfully.',
            'data' => null,
        ]);
    }
}
