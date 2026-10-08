<?php

namespace App\Services;

use Carbon\CarbonInterval;
use Defuse\Crypto\Crypto;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class PassportTokenService
{
    /**
     * How long a successful refresh result stays reusable for the same
     * presented refresh token. Concurrent BFF requests (proxy + RSC, or
     * parallel navigations) otherwise race Passport's one-time rotation and
     * force a logout.
     */
    private const REFRESH_REUSE_SECONDS = 30;

    /**
     * Exchange user credentials for a password-grant token pair.
     *
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string|null, session_ends_at: int}
     */
    public function issuePasswordToken(string $email, string $password, string $scope = '*', bool $remember = false): array
    {
        $lifetime = $remember ? CarbonInterval::days(30) : CarbonInterval::hours(12);
        $sessionEndsAt = now()->add($lifetime)->getTimestamp();

        $token = $this->withRefreshLifetime($lifetime, fn (): array => $this->requestToken([
            'grant_type' => 'password',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'username' => $email,
            'password' => $password,
            'scope' => $scope,
        ]));

        $token['session_ends_at'] = $sessionEndsAt;

        return $token;
    }

    /**
     * Exchange a refresh token for a new access token pair.
     *
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string|null}
     */
    public function refreshToken(string $refreshToken, string $scope = '*'): array
    {
        $cacheKey = 'passport:refresh:'.hash('sha256', $refreshToken);
        $lockKey = $cacheKey.':lock';

        try {
            return Cache::lock($lockKey, 10)->block(5, function () use ($refreshToken, $scope, $cacheKey) {
                /** @var array{token_type: string, expires_in: int, access_token: string, refresh_token: string|null}|null $cached */
                $cached = Cache::get($cacheKey);

                if ($this->isTokenPair($cached)) {
                    return $cached;
                }

                $token = $this->withRefreshLifetime($this->remainingRefreshLifetime($refreshToken), fn (): array => $this->requestToken([
                    'grant_type' => 'refresh_token',
                    'client_id' => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                    'refresh_token' => $refreshToken,
                    'scope' => $scope,
                ]));

                Cache::put($cacheKey, $token, now()->addSeconds(self::REFRESH_REUSE_SECONDS));

                return $token;
            });
        } catch (LockTimeoutException) {
            /** @var array{token_type: string, expires_in: int, access_token: string, refresh_token: string|null}|null $cached */
            $cached = Cache::get($cacheKey);

            if ($this->isTokenPair($cached)) {
                return $cached;
            }

            throw new HttpException(
                HttpResponse::HTTP_SERVICE_UNAVAILABLE,
                'Token refresh is busy. Retry shortly.',
            );
        }
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string|null}
     */
    private function requestToken(array $parameters): array
    {
        $response = app()->handle(
            Request::create('/oauth/token', 'POST', $parameters, server: [
                'HTTP_ACCEPT' => 'application/json',
            ])
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->getContent(), true) ?? [];

        if ($response->getStatusCode() >= HttpResponse::HTTP_BAD_REQUEST) {
            $message = is_string($payload['message'] ?? null)
                ? $payload['message']
                : 'Unable to issue access token.';

            if (in_array($response->getStatusCode(), [HttpResponse::HTTP_BAD_REQUEST, HttpResponse::HTTP_UNAUTHORIZED], true)) {
                throw ValidationException::withMessages([
                    'email' => [$message],
                ]);
            }

            throw new HttpException($response->getStatusCode(), $message);
        }

        return [
            'token_type' => (string) ($payload['token_type'] ?? 'Bearer'),
            'expires_in' => (int) ($payload['expires_in'] ?? 0),
            'access_token' => (string) ($payload['access_token'] ?? ''),
            'refresh_token' => isset($payload['refresh_token'])
                ? (string) $payload['refresh_token']
                : null,
        ];
    }

    /**
     * @return ($value is array{token_type: string, expires_in: int, access_token: string, refresh_token: string|null} ? true : false)
     */
    private function isTokenPair(mixed $value): bool
    {
        return is_array($value)
            && isset($value['access_token'])
            && is_string($value['access_token'])
            && $value['access_token'] !== ''
            && isset($value['expires_in'])
            && is_int($value['expires_in']);
    }

    /**
     * The OAuth grant copies the refresh lifetime when the authorization
     * server is first built. Forget that instance so this request's interval
     * is the one sealed into the new refresh token.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withRefreshLifetime(CarbonInterval|\DateInterval $lifetime, callable $callback): mixed
    {
        $previous = Passport::$refreshTokensExpireIn;
        Passport::refreshTokensExpireIn($lifetime);
        app()->forgetInstance(AuthorizationServer::class);

        try {
            return $callback();
        } finally {
            Passport::$refreshTokensExpireIn = $previous;
            app()->forgetInstance(AuthorizationServer::class);
        }
    }

    private function remainingRefreshLifetime(string $encryptedRefreshToken): \DateInterval
    {
        try {
            $json = Crypto::decryptWithPassword($encryptedRefreshToken, (string) app('encrypter')->getKey());
            $data = json_decode($json, true);
            $expire = is_array($data) ? (int) ($data['expire_time'] ?? 0) : 0;
            $seconds = max(1, $expire - time());

            return new \DateInterval('PT'.$seconds.'S');
        } catch (Throwable) {
            return Passport::refreshTokensExpireIn();
        }
    }

    private function clientId(): string
    {
        $clientId = config('services.passport.password_client_id');

        if (! is_string($clientId) || $clientId === '') {
            throw new HttpException(HttpResponse::HTTP_INTERNAL_SERVER_ERROR, 'Passport password client is not configured.');
        }

        return $clientId;
    }

    private function clientSecret(): string
    {
        $clientSecret = config('services.passport.password_client_secret');

        if (! is_string($clientSecret) || $clientSecret === '') {
            throw new HttpException(HttpResponse::HTTP_INTERNAL_SERVER_ERROR, 'Passport password client is not configured.');
        }

        return $clientSecret;
    }
}
