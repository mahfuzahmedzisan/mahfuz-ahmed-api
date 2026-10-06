<?php

use App\Models\User;
use App\Services\ImageConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\ClientRepository;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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

it('updates authenticated user preferences', function (): void {
    User::factory()->create([
        'email' => 'prefs@example.com',
        'password' => 'Password1!',
    ]);

    $login = loginAs('prefs@example.com');

    $this->withToken($login['access_token'])
        ->putJson('/api/v1/profile/preferences', [
            'email_notifications' => false,
            'push_notifications' => true,
            'theme' => 'dark',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.email_notifications', false)
        ->assertJsonPath('data.user.push_notifications', true)
        ->assertJsonPath('data.user.theme', 'dark');

    $this->assertDatabaseHas('users', [
        'email' => 'prefs@example.com',
        'email_notifications' => false,
        'push_notifications' => true,
        'theme' => 'dark',
    ]);
});

it('rejects an invalid theme preference', function (): void {
    User::factory()->create([
        'email' => 'prefs-invalid@example.com',
        'password' => 'Password1!',
    ]);

    $login = loginAs('prefs-invalid@example.com');

    $this->withToken($login['access_token'])
        ->putJson('/api/v1/profile/preferences', [
            'theme' => 'neon',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['theme']);
});

it('uploads and removes an authenticated user avatar', function (): void {
    Storage::fake('public');

    User::factory()->create([
        'email' => 'avatar@example.com',
        'password' => 'Password1!',
    ]);

    $login = loginAs('avatar@example.com');

    $upload = $this->withToken($login['access_token'])
        ->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 800, 600),
        ], [
            'Accept' => 'application/json',
        ])
        ->assertOk();

    $avatarUrl = $upload->json('data.user.avatar');
    expect($avatarUrl)->toEndWith('.webp');

    $user = User::query()->where('email', 'avatar@example.com')->firstOrFail();
    expect($user->avatar)->toEndWith('.webp');
    Storage::disk('public')->assertExists($user->avatar);

    $this->withToken($login['access_token'])
        ->deleteJson('/api/v1/profile/avatar')
        ->assertOk()
        ->assertJsonPath('data.user.avatar', null);

    Storage::disk('public')->assertMissing($user->avatar);
    $this->assertDatabaseHas('users', [
        'email' => 'avatar@example.com',
        'avatar' => null,
    ]);
});

it('replaces an avatar and deletes the previous file', function (): void {
    Storage::fake('public');

    User::factory()->create([
        'email' => 'avatar-replace@example.com',
        'password' => 'Password1!',
    ]);

    $login = loginAs('avatar-replace@example.com');

    $this->withToken($login['access_token'])
        ->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('first.jpg', 400, 400),
        ], [
            'Accept' => 'application/json',
        ])
        ->assertOk();

    $first = User::query()->where('email', 'avatar-replace@example.com')->firstOrFail()->avatar;

    $this->withToken($login['access_token'])
        ->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('second.png', 640, 480),
        ], [
            'Accept' => 'application/json',
        ])
        ->assertOk();

    $second = User::query()->where('email', 'avatar-replace@example.com')->firstOrFail()->avatar;

    expect($second)->not->toBe($first)
        ->and($second)->toEndWith('.webp');

    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});

it('deletes the avatar file when the account is deleted', function (): void {
    Storage::fake('public');

    $user = User::factory()->create([
        'email' => 'avatar-account@example.com',
        'password' => 'Password1!',
    ]);

    $login = loginAs('avatar-account@example.com');

    $this->withToken($login['access_token'])
        ->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('keep.jpg', 300, 300),
        ], [
            'Accept' => 'application/json',
        ])
        ->assertOk();

    $path = $user->fresh()->avatar;
    expect($path)->not->toBeNull();
    Storage::disk('public')->assertExists($path);

    $this->withToken($login['access_token'])
        ->deleteJson('/api/v1/profile', [
            'current_password' => 'Password1!',
        ])
        ->assertOk();

    Storage::disk('public')->assertMissing($path);
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

it('stores an svg avatar in its original format', function (): void {
    Storage::fake('public');

    User::factory()->create([
        'email' => 'avatar-svg@example.com',
        'password' => 'Password1!',
    ]);

    $login = loginAs('avatar-svg@example.com');

    $upload = $this->withToken($login['access_token'])
        ->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->createWithContent(
                'avatar.svg',
                '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"></svg>',
            ),
        ], [
            'Accept' => 'application/json',
        ])
        ->assertOk();

    expect($upload->json('data.user.avatar'))->toEndWith('.svg');

    $user = User::query()->where('email', 'avatar-svg@example.com')->firstOrFail();
    expect($user->avatar)->toEndWith('.svg');
    Storage::disk('public')->assertExists($user->avatar);
});

it('returns an unprocessable envelope when avatar encoding fails', function (): void {
    User::factory()->create([
        'email' => 'avatar-fail@example.com',
        'password' => 'Password1!',
    ]);

    $this->mock(ImageConversionService::class, function ($mock): void {
        $mock->shouldReceive('convertAndStore')
            ->once()
            ->andThrow(new RuntimeException('WebP encoding is unavailable. Enable GD WebP or install cwebp.'));
    });

    $login = loginAs('avatar-fail@example.com');

    $this->withToken($login['access_token'])
        ->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ], [
            'Accept' => 'application/json',
        ])
        ->assertStatus(HttpResponse::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonPath('message', 'WebP encoding is unavailable. Enable GD WebP or install cwebp.')
        ->assertJsonPath('data', null);
});
