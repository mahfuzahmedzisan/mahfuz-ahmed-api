<?php

namespace App\Services;

use App\Models\User;

class AuthTokenService
{
    public const BROWSER_SESSION_HOURS = 12;

    public const REMEMBER_SESSION_DAYS = 30;

    /**
     * Issue a Sanctum token for the Next.js BFF. The token never reaches the
     * browser; the BFF keeps it in its encrypted httpOnly session cookie and
     * the session ends when the token expires.
     *
     * @return array{token_type: string, access_token: string, session_ends_at: int}
     */
    public function issue(User $user, bool $remember = false, string $name = 'web'): array
    {
        $expiresAt = $remember
            ? now()->addDays(self::REMEMBER_SESSION_DAYS)
            : now()->addHours(self::BROWSER_SESSION_HOURS);

        $token = $user->createToken($name, ['*'], $expiresAt);

        return [
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'session_ends_at' => $expiresAt->getTimestamp(),
        ];
    }
}
