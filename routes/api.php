<?php

use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BroadcastAuthController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RealtimeController;
use App\Http\Controllers\Api\V1\TwoFactorAuthenticationController;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('two-factor-challenge', [AuthController::class, 'twoFactorChallenge'])->middleware('throttle:two-factor');
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::post('forgot-password', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:forgot-password');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:reset-password');

    Route::middleware('auth:api')->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:api')->group(function (): void {
    Route::post('broadcasting/auth', [BroadcastAuthController::class, 'authenticate']);

    Route::get('realtime/ping', [RealtimeController::class, 'ping']);
    Route::get('realtime/health', [RealtimeController::class, 'health']);
    Route::post('realtime/test-notification', [RealtimeController::class, 'testNotification']);
});

Route::middleware('auth:api')->prefix('profile')->group(function (): void {
    Route::put('/', [ProfileController::class, 'update']);
    Route::put('password', [ProfileController::class, 'updatePassword']);
    Route::put('preferences', [ProfileController::class, 'updatePreferences']);
    Route::post('avatar', [ProfileController::class, 'updateAvatar']);
    Route::delete('avatar', [ProfileController::class, 'destroyAvatar']);
    Route::delete('/', [ProfileController::class, 'destroy']);

    Route::prefix('two-factor-authentication')->group(function (): void {
        Route::get('/', [TwoFactorAuthenticationController::class, 'show']);
        Route::post('/', [TwoFactorAuthenticationController::class, 'enable']);
        Route::post('confirm', [TwoFactorAuthenticationController::class, 'confirm']);
        Route::delete('/', [TwoFactorAuthenticationController::class, 'disable']);
        Route::post('recovery-codes', [TwoFactorAuthenticationController::class, 'recoveryCodes']);
    });
});

/*
 * No admin-only resources remain after stripping the portfolio/media/
 * taxonomy surface back out in Phase 2. This route exists purely to prove
 * the `role` middleware works end-to-end (see Phase 9's authorization
 * test) and as the pattern to copy for whatever admin-only endpoints get
 * added back later: stack `role:admin` on top of `auth:api`, never rely on
 * `auth:api` alone to gate privileged actions.
 */
Route::middleware(['auth:api', 'role:admin', 'throttle:admin-users'])->group(function (): void {
    Route::get('admin/ping', function (Request $request) {
        return ApiResponse::success('Admin access confirmed.', [
            'user_id' => $request->user()->id,
        ]);
    });

    Route::get('admin/users', [UserController::class, 'index']);
});
