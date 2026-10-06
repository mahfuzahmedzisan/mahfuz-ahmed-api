<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

function adminToken(): string
{
    User::factory()->admin()->create([
        'name' => 'Ada Admin',
        'email' => 'ada-admin@example.com',
        'password' => 'Password1!',
    ]);

    User::factory()->member()->create([
        'name' => 'Grace Member',
        'email' => 'grace-member@example.com',
        'password' => 'Password1!',
    ]);

    return test()->postJson('/api/v1/auth/login', [
        'email' => 'ada-admin@example.com',
        'password' => 'Password1!',
    ])->json('data.access_token');
}

it('requires authentication to list users', function (): void {
    $this->getJson('/api/v1/admin/users')->assertUnauthorized();
});

it('forbids a regular user from listing users', function (): void {
    User::factory()->member()->create([
        'email' => 'member-list@example.com',
        'password' => 'Password1!',
    ]);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'member-list@example.com',
        'password' => 'Password1!',
    ])->json('data.access_token');

    $this->withToken($token)->getJson('/api/v1/admin/users')->assertForbidden();
});

it('searches users by name with the eloquent fallback', function (): void {
    $token = adminToken();

    $this->withToken($token)
        ->getJson('/api/v1/admin/users?q=Grace')
        ->assertOk()
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.users.0.email', 'grace-member@example.com');
});

it('filters users by role and sorts by name', function (): void {
    $token = adminToken();

    $this->withToken($token)
        ->getJson('/api/v1/admin/users?filter[role]=admin&sort=name')
        ->assertOk()
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.users.0.email', 'ada-admin@example.com');
});

it('falls back to eloquent search when the remote engine fails', function (): void {
    $token = adminToken();

    config([
        'scout.driver' => 'typesense',
        'scout.typesense.client-settings.num_retries' => 0,
        'scout.typesense.client-settings.connection_timeout_seconds' => 1,
        'scout.typesense.client-settings.nodes' => [[
            'host' => '127.0.0.1',
            'port' => '1',
            'path' => '',
            'protocol' => 'http',
        ]],
        'scout.typesense.client-settings.nearest_node' => [
            'host' => '127.0.0.1',
            'port' => '1',
            'path' => '',
            'protocol' => 'http',
        ],
    ]);

    $this->withToken($token)
        ->getJson('/api/v1/admin/users?q=Grace')
        ->assertOk()
        ->assertJsonPath('data.users.0.email', 'grace-member@example.com');
});

it('does not put secrets in the searchable document', function (): void {
    $user = User::factory()->create([
        'name' => 'Indexed User',
        'email' => 'indexed@example.com',
    ]);

    $document = $user->toSearchableArray();

    expect($document)->toHaveKeys(['id', 'name', 'email', 'role', 'created_at'])
        ->and($document)->not->toHaveKey('password')
        ->and($document)->not->toHaveKey('two_factor_secret')
        ->and($document['id'])->toBe((string) $user->id);
});

it('imports every searchable model', function (): void {
    $this->artisan('scout:import-all', ['--skip-sync-settings' => true])
        ->expectsOutputToContain('App\\Models\\User')
        ->assertSuccessful();
});
