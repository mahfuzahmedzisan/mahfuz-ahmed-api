<?php

namespace App\Http\Controllers\Internal;

use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateHlsJob;
use App\Models\Video;
use App\Services\VideoUploadTokenService;
use App\Support\AllowedVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TusHookController extends Controller
{
    public function __invoke(Request $request, VideoUploadTokenService $tokens, ?string $hook = null): JsonResponse
    {
        $name = strtolower($hook ?: (string) $request->header('Hook-Name', (string) $request->input('Type', '')));

        return match ($name) {
            'pre-create' => $this->preCreate($request, $tokens),
            'post-finish' => $this->postFinish($request, $tokens),
            'post-terminate' => $this->postTerminate($request),
            default => response()->json(['ok' => true]),
        };
    }

    private function preCreate(Request $request, VideoUploadTokenService $tokens): JsonResponse
    {
        $upload = $this->upload($request);
        $video = $this->authorizedVideo($request, $tokens, $upload);

        if ($video instanceof JsonResponse) {
            return $video;
        }

        $filename = (string) ($upload['MetaData']['filename'] ?? '');
        $mime = (string) ($upload['MetaData']['filetype'] ?? '');
        $size = (int) ($upload['Size'] ?? 0);
        $deferred = (bool) ($upload['SizeIsDeferred'] ?? false);

        if ($deferred || $size < 1) {
            return $this->reject(422, 'The upload must include a total length.');
        }

        $reason = AllowedVideo::rejectionReason($filename, $mime, $size);

        if ($reason !== null) {
            return $this->reject(422, $reason);
        }

        if ($video->status !== VideoStatus::AwaitingUpload) {
            return $this->reject(409, 'This video is not waiting for an upload.');
        }

        $tusId = (string) ($upload['ID'] ?? '');

        if ($tusId !== '') {
            $video->forceFill(['tus_id' => $tusId])->save();
        }

        return response()->json(['ok' => true]);
    }

    private function postFinish(Request $request, VideoUploadTokenService $tokens): JsonResponse
    {
        $upload = $this->upload($request);
        $video = $this->videoFromMetadata($upload);

        if ($video === null) {
            return $this->reject(404, 'Video was not found.');
        }

        if ($video->status !== VideoStatus::AwaitingUpload) {
            return response()->json(['ok' => true]);
        }

        $granted = $tokens->find($this->bearer($request));

        if ($granted === null || $granted['video_id'] !== $video->id) {
            return $this->reject(403, 'Upload token is invalid.');
        }

        $path = $upload['Storage']['Path'] ?? null;

        if (! is_string($path) || ! is_file($path)) {
            return $this->reject(422, 'Finished upload file is missing.');
        }

        $filename = $this->storedFilename((string) ($upload['MetaData']['filename'] ?? 'video.mp4'));

        $video->addMedia($path)
            ->usingFileName($filename)
            ->toMediaCollection('source');

        @unlink($path.'.info');

        $video->forceFill([
            'status' => VideoStatus::Uploaded,
            'tus_id' => (string) ($upload['ID'] ?? $video->tus_id),
            'error_message' => null,
        ])->save();

        $tokens->forget($this->bearer($request));

        GenerateHlsJob::dispatch($video->id);

        return response()->json(['ok' => true]);
    }

    private function postTerminate(Request $request): JsonResponse
    {
        $upload = $this->upload($request);
        $video = $this->videoFromMetadata($upload);

        if ($video === null) {
            $tusId = (string) ($upload['ID'] ?? '');
            $video = $tusId === '' ? null : Video::query()->where('tus_id', $tusId)->first();
        }

        if ($video !== null && $video->status === VideoStatus::AwaitingUpload) {
            $video->delete();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $upload
     */
    private function authorizedVideo(Request $request, VideoUploadTokenService $tokens, array $upload): Video|JsonResponse
    {
        $granted = $tokens->find($this->bearer($request));

        if ($granted === null) {
            return $this->reject(403, 'Upload token is invalid.');
        }

        $video = $this->videoFromMetadata($upload);

        if ($video === null || $granted['video_id'] !== $video->id || $granted['user_id'] !== (int) $video->uploaded_by) {
            return $this->reject(403, 'Upload token is invalid.');
        }

        return $video;
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(Request $request): array
    {
        $upload = $request->input('Event.Upload', []);

        return is_array($upload) ? $upload : [];
    }

    /**
     * @param  array<string, mixed>  $upload
     */
    private function videoFromMetadata(array $upload): ?Video
    {
        $metadata = $upload['MetaData'] ?? [];
        $videoId = is_array($metadata) ? (int) ($metadata['video_id'] ?? 0) : 0;

        if ($videoId < 1) {
            return null;
        }

        return Video::query()->find($videoId);
    }

    private function bearer(Request $request): string
    {
        $headers = $request->input('Event.HTTPRequest.Header', []);

        if (! is_array($headers)) {
            return '';
        }

        foreach ($headers as $name => $values) {
            if (strcasecmp((string) $name, 'Authorization') !== 0) {
                continue;
            }

            $value = is_array($values) ? ($values[0] ?? '') : $values;

            if (is_string($value) && str_starts_with($value, 'Bearer ')) {
                return substr($value, 7);
            }
        }

        return '';
    }

    private function storedFilename(string $original): string
    {
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $base = Str::slug(pathinfo($original, PATHINFO_FILENAME)) ?: 'video';

        return $extension === '' ? $base : $base.'.'.$extension;
    }

    private function reject(int $status, string $message): JsonResponse
    {
        return response()->json([
            'RejectUpload' => true,
            'HTTPResponse' => [
                'StatusCode' => $status,
                'Body' => $message,
                'Header' => [
                    'Content-Type' => 'text/plain',
                ],
            ],
            'message' => $message,
        ], $status);
    }
}
