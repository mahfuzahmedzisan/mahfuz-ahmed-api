<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $client = app(ClientRepository::class)->createPasswordGrantClient(
        'Test Password Grant Client',
        'users',
        true,
    );

    config([
        'services.passport.password_client_id' => $client->getKey(),
        'services.passport.password_client_secret' => $client->plainSecret,
    ]);
});

it('registers a user and returns a bearer token', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
    ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Registered successfully.')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'admin@example.com')
        ->assertJsonStructure([
            'data' => [
                'access_token',
                'refresh_token',
                'expires_in',
                'user' => ['id', 'name', 'email', 'avatar'],
            ],
        ]);

    $this->assertDatabaseHas('users', [
        'email' => 'admin@example.com',
    ]);
});

it('logs in with valid credentials and returns a bearer token', function (): void {
    User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Logged in successfully.')
        ->assertJsonPath('data.user.email', 'admin@example.com')
        ->assertJsonStructure([
            'data' => ['access_token', 'refresh_token', 'expires_in'],
        ]);
});

it('rejects invalid login credentials', function (): void {
    User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'wrong-password',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('issues a password grant token via oauth token endpoint', function (): void {
    User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ]);

    /** @var Client $client */
    $client = Client::query()->whereJsonContains('grant_types', 'password')->firstOrFail();

    $response = $this->post('/oauth/token', [
        'grant_type' => 'password',
        'client_id' => $client->getKey(),
        'client_secret' => config('services.passport.password_client_secret'),
        'username' => 'admin@example.com',
        'password' => 'Password1!',
        'scope' => '*',
    ], [
        'Accept' => 'application/json',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'token_type',
            'expires_in',
            'access_token',
            'refresh_token',
        ]);
});

it('returns the authenticated user for me', function (): void {
    $user = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ]);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ])->json('data');

    $this->withToken($login['access_token'])
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.email', 'admin@example.com');
});

it('requires a bearer token for me', function (): void {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('revokes the current token on logout', function (): void {
    User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ]);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ])->json('data');

    $this->withToken($login['access_token'])
        ->postJson('/api/v1/auth/logout')
        ->assertOk()
        ->assertJsonPath('message', 'Logged out successfully.');

    $this->assertDatabaseHas('oauth_access_tokens', [
        'revoked' => true,
    ]);

    // Clear in-process guard cache so the next request re-validates the Bearer token.
    auth()->forgetGuards();

    $this->withToken($login['access_token'])
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

it('refreshes an access token', function (): void {
    User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ]);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ])->json('data');

    $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $login['refresh_token'],
    ])->assertOk()
        ->assertJsonStructure([
            'data' => ['access_token', 'refresh_token', 'expires_in', 'token_type'],
        ]);
});

it('throttles login after five attempts', function (): void {
    User::factory()->create([
        'email' => 'locked@example.com',
        'password' => 'Password1!',
    ]);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'locked@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'locked@example.com',
        'password' => 'wrong-password',
    ])->assertTooManyRequests();
});

it('requires a two factor challenge when the user has 2FA enabled', function (): void {
    $user = User::factory()->create([
        'email' => '2fa@example.com',
        'password' => 'Password1!',
    ]);

    enableConfirmedTwoFactor($user, 'recovery-code-alpha');

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => '2fa@example.com',
        'password' => 'Password1!',
    ]);

    $login->assertOk()
        ->assertJsonPath('data.two_factor', true)
        ->assertJsonStructure(['data' => ['challenge_token']])
        ->assertJsonMissingPath('data.access_token');

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge_token' => $login->json('data.challenge_token'),
        'recovery_code' => 'recovery-code-alpha',
    ])->assertOk()
        ->assertJsonPath('data.user.email', '2fa@example.com')
        ->assertJsonStructure([
            'data' => ['access_token', 'refresh_token', 'expires_in'],
        ]);
});

it('rejects an already-used two factor challenge token', function (): void {
    $user = User::factory()->create([
        'email' => '2fa-reuse@example.com',
        'password' => 'Password1!',
    ]);

    enableConfirmedTwoFactor($user, 'recovery-code-beta');

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => '2fa-reuse@example.com',
        'password' => 'Password1!',
    ]);

    $payload = [
        'challenge_token' => $login->json('data.challenge_token'),
        'recovery_code' => 'recovery-code-beta',
    ];

    $this->postJson('/api/v1/auth/two-factor-challenge', $payload)->assertOk();

    $this->postJson('/api/v1/auth/two-factor-challenge', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['challenge_token']);
});
