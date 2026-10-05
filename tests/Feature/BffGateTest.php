<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

uses(RefreshDatabase::class);

it('rejects api requests that do not present the bff secret', function (): void {
    $this->defaultHeaders = ['Accept' => 'application/json'];

    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ])->assertForbidden()
        ->assertJsonPath('message', 'This API only accepts requests from the trusted application.');
});

it('rejects a trusted secret from an origin that is not allowlisted', function (): void {
    $this->withHeaders([
        'X-Frontend-Origin' => 'https://mahfuz-steel.vercel.app',
    ])->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ])->assertForbidden()
        ->assertJsonPath('message', 'This API only accepts requests from the trusted application.');
});

it('accepts login from an allowlisted origin with the bff secret', function (): void {
    $client = app(ClientRepository::class)->createPasswordGrantClient(
        'Test Password Grant Client',
        'users',
        true,
    );

    config([
        'services.passport.password_client_id' => $client->getKey(),
        'services.passport.password_client_secret' => $client->plainSecret,
    ]);

    User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ])->assertOk()
        ->assertJsonPath('data.user.email', 'admin@example.com')
        ->assertJsonStructure([
            'data' => ['access_token', 'refresh_token'],
        ]);
});

it('keeps the health endpoint public', function (): void {
    $this->defaultHeaders = ['Accept' => 'application/json'];

    $this->get('/up')->assertOk();
});

it('still issues oauth tokens without bff headers', function (): void {
    $this->defaultHeaders = ['Accept' => 'application/json'];

    $client = app(ClientRepository::class)->createPasswordGrantClient(
        'Test Password Grant Client',
        'users',
        true,
    );

    config([
        'services.passport.password_client_id' => $client->getKey(),
        'services.passport.password_client_secret' => $client->plainSecret,
    ]);

    User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ]);

    /** @var Client $passwordClient */
    $passwordClient = Client::query()->whereJsonContains('grant_types', 'password')->firstOrFail();

    $this->post('/oauth/token', [
        'grant_type' => 'password',
        'client_id' => $passwordClient->getKey(),
        'client_secret' => config('services.passport.password_client_secret'),
        'username' => 'admin@example.com',
        'password' => 'Password1!',
        'scope' => '*',
    ], [
        'Accept' => 'application/json',
    ])->assertOk()
        ->assertJsonStructure(['access_token', 'refresh_token']);
});
