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

it('forbids a regular user from the admin ping route', function (): void {
    User::factory()->member()->create([
        'email' => 'member@example.com',
        'password' => 'Password1!',
    ]);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'member@example.com',
        'password' => 'Password1!',
    ])->json('data');

    $this->withToken($login['access_token'])
        ->getJson('/api/v1/admin/ping')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have permission to perform this action.');
});

it('allows an admin to reach the admin ping route', function (): void {
    $admin = User::factory()->admin()->create([
        'email' => 'admin-role@example.com',
        'password' => 'Password1!',
    ]);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin-role@example.com',
        'password' => 'Password1!',
    ])->json('data');

    $this->withToken($login['access_token'])
        ->getJson('/api/v1/admin/ping')
        ->assertOk()
        ->assertJsonPath('data.user_id', $admin->id);
});

it('requires authentication for the admin ping route', function (): void {
    $this->getJson('/api/v1/admin/ping')->assertUnauthorized();
});

it('assigns the non-privileged role to newly registered users', function (): void {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'New Member',
        'email' => 'new-member@example.com',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
    ])->assertCreated()
        ->assertJsonPath('data.user.role', 'user');

    $this->assertDatabaseHas('users', [
        'email' => 'new-member@example.com',
        'role' => 'user',
    ]);
});
