<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Fortify;

class TwoFactorAuthenticationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->apiSuccess('Two factor authentication status retrieved.', [
            'enabled' => $user->hasEnabledTwoFactorAuthentication(),
            'pending_confirmation' => ! is_null($user->two_factor_secret) && is_null($user->two_factor_confirmed_at),
        ]);
    }

    /**
     * Starts enrollment: generates (but does not yet confirm) a secret and
     * recovery codes, returning everything the Next.js app needs to render
     * the QR code and let the user confirm with a code from their app.
     */
    public function enable(Request $request, EnableTwoFactorAuthentication $enable): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
        ]);

        $user = $request->user();

        $enable($user, force: true);
        $user->refresh();

        return $this->apiSuccess('Two factor authentication enrollment started. Confirm with a code to finish.', [
            'secret' => Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            'qr_code_svg' => $user->twoFactorQrCodeSvg(),
            'recovery_codes' => $user->recoveryCodes(),
        ]);
    }

    public function confirm(Request $request, ConfirmTwoFactorAuthentication $confirm): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $confirm($request->user(), $request->string('code')->toString());

        return $this->apiSuccess('Two factor authentication confirmed.');
    }

    public function disable(Request $request, DisableTwoFactorAuthentication $disable): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
        ]);

        $disable($request->user());

        return $this->apiSuccess('Two factor authentication disabled.');
    }

    public function recoveryCodes(Request $request, GenerateNewRecoveryCodes $generate): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
        ]);

        $generate($request->user());

        return $this->apiSuccess('Recovery codes regenerated.', [
            'recovery_codes' => $request->user()->fresh()->recoveryCodes(),
        ]);
    }
}
