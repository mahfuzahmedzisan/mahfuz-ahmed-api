<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PassportTokenService
{
    /**
     * Exchange user credentials for a password-grant token pair.
     *
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string|null}
     */
    public function issuePasswordToken(string $email, string $password, string $scope = '*'): array
    {
        return $this->requestToken([
            'grant_type' => 'password',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'username' => $email,
            'password' => $password,
            'scope' => $scope,
        ]);
    }

    /**
     * Exchange a refresh token for a new access token pair.
     *
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string|null}
     */
    public function refreshToken(string $refreshToken, string $scope = '*'): array
    {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => $refreshToken,
            'scope' => $scope,
        ]);
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
