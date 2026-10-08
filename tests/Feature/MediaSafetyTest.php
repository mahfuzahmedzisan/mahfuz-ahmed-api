<?php

use App\Enums\VideoStatus;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function finishUpload(string $filename, string $mime, string $bytes, string $kindTitle = 'Sample'): array
{
    $admin = User::factory()->admin()->create();
    $created = test()->actingAs($admin, 'api')->postJson('/api/v1/admin/media', [
        'title' => $kindTitle,
        'filename' => $filename,
        'size' => strlen($bytes),
        'mime' => $mime,
    ]);

    if ($created->status() !== 201) {
        return ['response' => $created, 'id' => null];
    }

    $id = (int) $created->json('data.media_id');
    $token = (string) $created->json('data.upload_token');
    $path = tempnam(sys_get_temp_dir(), 'scan');
    file_put_contents($path, $bytes);

    $payload = [
        'Type' => 'post-finish',
        'Event' => [
            'Upload' => [
                'ID' => 'tus-scan',
                'Size' => strlen($bytes),
                'SizeIsDeferred' => false,
                'Offset' => strlen($bytes),
                'MetaData' => [
                    'filename' => $filename,
                    'filetype' => $mime,
                    'media_id' => (string) $id,
                ],
                'Storage' => ['Path' => $path],
            ],
            'HTTPRequest' => [
                'Header' => [
                    'Authorization' => ['Bearer '.$token],
                ],
            ],
        ],
    ];

    $finished = test()->withHeaders(['X-Tus-Hook-Secret' => 'test-tus-secret'])
        ->postJson('/internal/tus/post-finish', $payload);

    return ['response' => $finished, 'id' => $id, 'create' => $created];
}

it('rejects a php payload renamed as a jpeg', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin, 'api')->postJson('/api/v1/admin/media', [
        'title' => 'Photo',
        'filename' => 'shell.php.jpg',
        'size' => 32,
        'mime' => 'image/jpeg',
    ])->assertUnprocessable();
});

it('rejects an archive before it is stored', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin, 'api')->postJson('/api/v1/admin/media', [
        'title' => 'Bundle',
        'filename' => 'crack.zip',
        'size' => 32,
        'mime' => 'application/zip',
    ])->assertUnprocessable();
});

it('rejects a jpeg whose bytes are php', function (): void {
    $result = finishUpload('photo.jpg', 'image/jpeg', "<?php echo 'no';");

    expect($result['response']->status())->toBe(422);
    expect(MediaItem::query()->findOrFail($result['id'])->status)->toBe(VideoStatus::Failed);
    expect(MediaItem::query()->findOrFail($result['id'])->getFirstMedia('source'))->toBeNull();
});

it('numbers a shared name and skips slugs that already exist', function (): void {
    $admin = User::factory()->admin()->create();
    MediaItem::factory()->create(['title' => 'New image', 'slug' => 'new-image']);

    $this->actingAs($admin, 'api')
        ->getJson('/api/v1/admin/media/slug-preview?title=New%20image&count=2')
        ->assertOk()
        ->assertJsonPath('data.slugs.0', 'new-image-1')
        ->assertJsonPath('data.slugs.1', 'new-image-2');
});

it('accepts an svg and stores it without script', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><circle r="4"/></svg>';
    $result = finishUpload('mark.svg', 'image/svg+xml', $svg, 'Mark');

    expect($result['response']->status())->toBe(200);
    $item = MediaItem::query()->findOrFail($result['id']);
    expect($item->status)->toBe(VideoStatus::Ready);
    $path = $item->getFirstMediaPath('source');
    expect($path)->not->toBeNull();
    $stored = strtolower((string) file_get_contents((string) $path));
    expect($stored)->not->toContain('<script')->toContain('circle');
});

it('stores a raster image as webp', function (): void {
    if (! function_exists('imagewebp')) {
        test()->markTestSkipped('GD WebP is not available.');
    }

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $result = finishUpload('photo.png', 'image/png', $png, 'Photo');

    expect($result['response']->status())->toBe(200);
    $item = MediaItem::query()->findOrFail($result['id']);
    expect($item->extension)->toBe('webp');
    expect($item->mime)->toBe('image/webp');
    $path = (string) $item->getFirstMediaPath('source');
    expect($path)->toEndWith('.webp');
    $header = (string) file_get_contents($path, false, null, 0, 12);
    expect($header)->toStartWith('RIFF')->toContain('WEBP');
});

it('rejects a file whose magic bytes do not match the extension', function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $result = finishUpload('photo.jpg', 'image/jpeg', $png);

    expect($result['response']->status())->toBe(422);
    expect(MediaItem::query()->findOrFail($result['id'])->getFirstMedia('source'))->toBeNull();
});
