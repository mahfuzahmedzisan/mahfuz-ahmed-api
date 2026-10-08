<?php

use App\Contracts\EncodesHls;
use App\Enums\VideoStatus;
use App\Jobs\GenerateHlsJob;
use App\Models\User;
use App\Models\Video;
use App\Services\HlsEncodeResult;
use App\Services\HlsTranscodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function videoPayload(string $token, int $videoId, array $overrides = []): array
{
    $upload = array_merge([
        'ID' => 'tus-upload-1',
        'Size' => 128,
        'SizeIsDeferred' => false,
        'Offset' => 0,
        'MetaData' => [
            'filename' => 'clip.mp4',
            'filetype' => 'video/mp4',
            'video_id' => (string) $videoId,
        ],
        'Storage' => [
            'Path' => '',
        ],
    ], $overrides);

    return [
        'Type' => 'pre-create',
        'Event' => [
            'Upload' => $upload,
            'HTTPRequest' => [
                'Header' => [
                    'Authorization' => ['Bearer '.$token],
                ],
            ],
        ],
    ];
}

it('creates a video and an upload token without accepting a file body', function (): void {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin, 'api')->postJson('/api/v1/admin/videos', [
        'title' => 'Launch film',
        'filename' => 'launch.mp4',
        'size' => 2048,
        'mime' => 'video/mp4',
    ])->assertCreated()
        ->assertJsonPath('data.video.status', 'awaiting_upload')
        ->assertJsonPath('data.video.stream_url', null)
        ->assertJsonStructure([
            'data' => ['video_id', 'tus_endpoint', 'upload_token', 'video'],
        ]);

    expect($response->json('data.upload_token'))->toBeString()->not->toBeEmpty();
    expect($response->json('data.tus_endpoint'))->toEndWith('/tus/');
    expect($response->json('data.video'))->not->toHaveKey('source_url');

    $this->assertDatabaseHas('videos', [
        'id' => $response->json('data.video_id'),
        'status' => 'awaiting_upload',
        'uploaded_by' => $admin->id,
    ]);
});

it('rejects a multipart file on video create', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin, 'api')->post('/api/v1/admin/videos', [
        'title' => 'Launch film',
        'filename' => 'launch.mp4',
        'size' => 2048,
        'mime' => 'video/mp4',
        'file' => UploadedFile::fake()->create('launch.mp4', 20, 'video/mp4'),
    ])->assertUnprocessable();
});

it('forbids a regular user and a missing bff secret from creating videos', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member, 'api')->postJson('/api/v1/admin/videos', [
        'title' => 'Nope',
        'filename' => 'nope.mp4',
        'size' => 10,
        'mime' => 'video/mp4',
    ])->assertForbidden();

    $this->defaultHeaders = ['Accept' => 'application/json'];

    $this->postJson('/api/v1/admin/videos', [
        'title' => 'Nope',
        'filename' => 'nope.mp4',
        'size' => 10,
        'mime' => 'video/mp4',
    ])->assertForbidden()
        ->assertJsonPath('message', 'This API only accepts requests from the trusted application.');
});

it('rejects a pre-create hook with a bad token or a non-video', function (): void {
    $admin = User::factory()->admin()->create();
    $created = $this->actingAs($admin, 'api')->postJson('/api/v1/admin/videos', [
        'title' => 'Clip',
        'filename' => 'clip.mp4',
        'size' => 128,
        'mime' => 'video/mp4',
    ])->assertCreated();

    $videoId = (int) $created->json('data.video_id');
    $token = (string) $created->json('data.upload_token');

    $this->withHeaders(['X-Tus-Hook-Secret' => 'test-tus-secret'])
        ->postJson('/internal/tus/pre-create', videoPayload('not-the-token', $videoId))
        ->assertForbidden();

    $this->withHeaders(['X-Tus-Hook-Secret' => 'test-tus-secret'])
        ->postJson('/internal/tus/pre-create', videoPayload($token, $videoId, [
            'MetaData' => [
                'filename' => 'notes.txt',
                'filetype' => 'text/plain',
                'video_id' => (string) $videoId,
            ],
        ]))
        ->assertUnprocessable();
});

it('stores the source and dispatches the media job when tus finishes', function (): void {
    Storage::fake('public');
    Bus::fake();

    $admin = User::factory()->admin()->create();
    $created = $this->actingAs($admin, 'api')->postJson('/api/v1/admin/videos', [
        'title' => 'Clip',
        'filename' => 'clip.mp4',
        'size' => 4,
        'mime' => 'video/mp4',
    ])->assertCreated();

    $videoId = (int) $created->json('data.video_id');
    $token = (string) $created->json('data.upload_token');
    $path = tempnam(sys_get_temp_dir(), 'tus');
    file_put_contents($path, 'fake');

    $this->withHeaders(['X-Tus-Hook-Secret' => 'test-tus-secret'])
        ->postJson('/internal/tus/pre-create', videoPayload($token, $videoId, [
            'Size' => 4,
            'Storage' => ['Path' => $path],
        ]))
        ->assertOk();

    $this->withHeaders(['X-Tus-Hook-Secret' => 'test-tus-secret'])
        ->postJson('/internal/tus/post-finish', videoPayload($token, $videoId, [
            'Size' => 4,
            'Offset' => 4,
            'Storage' => ['Path' => $path],
        ]))
        ->assertOk();

    $video = Video::query()->findOrFail($videoId);

    expect($video->status)->toBe(VideoStatus::Uploaded);
    expect($video->getFirstMedia('source'))->not->toBeNull();

    Bus::assertDispatched(GenerateHlsJob::class, function (GenerateHlsJob $job) use ($videoId): bool {
        return $job->videoId === $videoId && $job->queue === 'media';
    });
});

it('marks a video ready from a mocked encoder and never plays the raw source', function (): void {
    Storage::fake('public');

    $this->app->instance(EncodesHls::class, new class implements EncodesHls
    {
        public function export(
            string $disk,
            string $sourceRelativePath,
            string $playlistRelativePath,
            ?callable $onProgress = null,
        ): HlsEncodeResult {
            if ($onProgress !== null) {
                $onProgress(40);
            }

            Storage::disk($disk)->put($playlistRelativePath, "#EXTM3U\n");

            return new HlsEncodeResult(9, 1280, 720);
        }
    });

    $video = Video::factory()->uploaded()->create();
    $source = tempnam(sys_get_temp_dir(), 'src');
    file_put_contents($source, 'source-bytes');
    $video->addMedia($source)->usingFileName('secret-source.mp4')->toMediaCollection('source');

    (new GenerateHlsJob($video->id))->handle(app(HlsTranscodeService::class));

    $video->refresh();

    expect($video->status)->toBe(VideoStatus::Ready);
    expect($video->hls_path)->toEndWith('master.m3u8');
    expect($video->progress)->toBe(100);
    Storage::disk('public')->assertExists($video->hls_path);

    $admin = User::factory()->admin()->create();

    $payload = $this->actingAs($admin, 'api')
        ->getJson('/api/v1/admin/videos/'.$video->id)
        ->assertOk()
        ->json('data.video');

    expect($payload['stream_url'])->toContain('master.m3u8');
    expect(json_encode($payload))->not->toContain('secret-source');
});

it('deletes the video, its media, and the hls directory', function (): void {
    Storage::fake('public');

    $admin = User::factory()->admin()->create();
    $video = Video::factory()->ready()->create(['uploaded_by' => $admin->id]);
    Storage::disk('public')->put($video->hls_path, "#EXTM3U\n");
    $source = tempnam(sys_get_temp_dir(), 'src');
    file_put_contents($source, 'source-bytes');
    $video->addMedia($source)->usingFileName('secret-source.mp4')->toMediaCollection('source');

    $this->actingAs($admin, 'api')
        ->deleteJson('/api/v1/admin/videos/'.$video->id)
        ->assertOk();

    $this->assertDatabaseMissing('videos', ['id' => $video->id]);
    $this->assertDatabaseMissing('media', ['model_id' => $video->id, 'model_type' => Video::class]);
    Storage::disk('public')->assertMissing($video->hls_path);
});

it('rejects tus hooks that omit the shared secret', function (): void {
    $this->postJson('/internal/tus/pre-create', videoPayload('token', 1))
        ->assertForbidden()
        ->assertJsonPath('message', 'Invalid tus hook secret.');
});
