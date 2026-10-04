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

/**
 * @return array{access_token: string, refresh_token: string, expires_in: int, user: array<string, mixed>}
 */
function loginAs(string $email, string $password = 'Password1!'): array
{
    return test()->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => $password,
    ])->json('data');
}

it('updates the authenticated user profile', function (): void {
    User::factory()->create([
        'email' => 'profile@example.com',
        'password' => 'Password1!',
        'name' => 'Before',
    ]);

    $login = loginAs('profile@example.com');

    $this->withToken($login['access_token'])
        ->putJson('/api/v1/profile', [
            'name' => 'After',
            'email' => 'profile-updated@example.com',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.name', 'After')
        ->assertJsonPath('data.user.email', 'profile-updated@example.com');

    $this->assertDatabaseHas('users', [
        'email' => 'profile-updated@example.com',
        'name' => 'After',
    ]);
});

it('requires authentication to update a profile', function (): void {
    $this->putJson('/api/v1/profile', [
        'name' => 'After',
        'email' => 'after@example.com',
    ])->assertUnauthorized();
});

it('updates the authenticated user password', function (): void {
    User::factory()->create([
        'email' => 'password@example.com',
        'password' => 'Password1!',
    ]);

    $login = loginAs('password@example.com');

    $this->withToken($login['access_token'])
        ->putJson('/api/v1/profile/password', [
            'current_password' => 'Password1!',
            'password' => 'Password2!',
            'password_confirmation' => 'Password2!',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Password updated successfully.');

    $this->postJson('/api/v1/auth/login', [
        'email' => 'password@example.com',
        'password' => 'Password2!',
    ])->assertOk();
});

it('rejects a password update when the current password is wrong', function (): void {
    User::factory()->create([
        'email' => 'password-wrong@example.com',
        'password' => 'Password1!',
    ]);

    $login = loginAs('password-wrong@example.com');

    $this->withToken($login['access_token'])
        ->putJson('/api/v1/profile/password', [
            'current_password' => 'not-the-password',
            'password' => 'Password2!',
            'password_confirmation' => 'Password2!',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);
});

it('deletes the authenticated account and revokes tokens', function (): void {
    $user = User::factory()->create([
        'email' => 'delete-me@example.com',
        'password' => 'Password1!',
    ]);

    $login = loginAs('delete-me@example.com');

    $this->withToken($login['access_token'])
        ->deleteJson('/api/v1/profile', [
            'current_password' => 'Password1!',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Account deleted successfully.');

    $this->assertDatabaseMissing('users', [
        'id' => $user->id,
    ]);

    auth()->forgetGuards();

    $this->withToken($login['access_token'])
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});
